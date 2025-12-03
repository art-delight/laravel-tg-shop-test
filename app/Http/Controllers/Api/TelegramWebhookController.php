<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramBotService $telegram)
    {
    }

    public function handle(Request $request, $secret)
    {
        if ($secret !== env('TELEGRAM_BOT_WEBHOOK_SECRET')) {
            abort(403);
        }

        $upd = $request->all();

        if (isset($upd['message'])) {
            $this->handleMessage($upd['message']);
        }

        if (isset($upd['callback_query'])) {
            $this->handleCallback($upd['callback_query']);
        }

        return response()->json(['ok' => true]);
    }

    protected function getUser($from): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $from['id']],
            [
                'username'   => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name'  => $from['last_name'] ?? null,
            ]
        );
    }

    /* ==========================
     *   Обработка сообщений
     * ========================== */

    protected function handleMessage($m)
    {
        $chat = $m['chat']['id'];
        $text = trim($m['text'] ?? '');
        $user = $this->getUser($m['from']);

        // Если ждём телефон для оформления заказа
        if ($user->state === 'waiting_contact') {
            $this->handleContactInput($user, $chat, $text);
            return;
        }

        // /start — главное меню
        if (str_starts_with($text, '/start')) {
            $user->update([
                'state'         => 'main_menu',
                'state_payload' => null,
            ]);

            $this->sendMainMenu($chat);
            return;
        }

        // Обработка основного меню
        switch ($text) {
            case '🛍 Каталог':
                $user->update(['state' => 'browse', 'state_payload' => null]);
                $this->sendProducts($chat);
                break;

            case '🧺 Корзина':
                $this->sendCart($user, $chat);
                break;

            case '📦 Мои заказы':
                $this->sendOrdersList($user, $chat);
                break;

            case 'ℹ Помощь':
                $this->telegram->sendMessage(
                    $chat,
                    "Доступные команды:\n" .
                    "/start — главное меню\n" .
                    "🛍 Каталог — список товаров\n" .
                    "🧺 Корзина — посмотреть корзину\n" .
                    "📦 Мои заказы — история заказов"
                );
                break;

            default:
                $this->telegram->sendMessage($chat, "Не понял. Используй /start");
        }
    }

    /* ==========================
     *   Обработка callback-кнопок
     * ========================== */

    protected function handleCallback($cb)
    {
        $id   = $cb['id'];
        $data = $cb['data'] ?? '';
        $chat = $cb['message']['chat']['id'] ?? null;
        $from = $cb['from'] ?? null;

        if (!$chat || !$from) {
            $this->telegram->answerCallbackQuery($id);
            return;
        }

        $user = $this->getUser($from);

        // Открыть каталог
        if ($data === 'catalog') {
            $this->sendProducts($chat);
            $this->telegram->answerCallbackQuery($id);
            return;
        }

        // Открыть корзину
        if ($data === 'cart_open') {
            $this->sendCart($user, $chat);
            $this->telegram->answerCallbackQuery($id);
            return;
        }

        // Очистить корзину
        if ($data === 'cart_clear') {
            $this->saveCart($user, []);
            $this->telegram->sendMessage($chat, "Корзина очищена.");
            $this->telegram->answerCallbackQuery($id, 'Корзина очищена');
            return;
        }

        // Начать оформление заказа из корзины
        if ($data === 'cart_checkout') {
            $cart = $this->getCart($user);
            if (empty($cart)) {
                $this->telegram->sendMessage($chat, "У вас пустая корзина.");
                $this->telegram->answerCallbackQuery($id, 'Корзина пуста', true);
                return;
            }

            $user->state = 'waiting_contact';
            $user->state_payload = ['mode' => 'cart_checkout'];
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "Почти готово! 👌\n\nОтправьте, пожалуйста, ваш номер телефона для оформления заказа."
            );
            $this->telegram->answerCallbackQuery($id, 'Введите телефон');
            return;
        }

        // product:ID — показать товар + «добавить в корзину»
        if (str_starts_with($data, 'product:')) {
            $pid = (int) str_replace('product:', '', $data);
            $p   = Product::where('is_active', 1)->find($pid);

            if (!$p) {
                $this->telegram->answerCallbackQuery($id, "Товар не найден", true);
                return;
            }

            $txt =
                "<b>{$p->title}</b>\n\n" .
                ($p->description ? $p->description . "\n\n" : '') .
                "Цена: <b>{$p->price}</b>";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text'          => '➕ В корзину',
                            'callback_data' => "cart_add:{$p->id}",
                        ],
                    ],
                    [
                        [
                            'text'          => '🧺 Корзина',
                            'callback_data' => 'cart_open',
                        ],
                        [
                            'text'          => '⬅ Каталог',
                            'callback_data' => 'catalog',
                        ],
                    ],
                ],
            ];

            $this->telegram->sendMessage($chat, $txt, [
                'reply_markup' => json_encode($keyboard),
            ]);

            $this->telegram->answerCallbackQuery($id, "Открываю товар");
            return;
        }

        // cart_add:ID — добавить товар в корзину
        if (str_starts_with($data, 'cart_add:')) {
            $pid = (int) str_replace('cart_add:', '', $data);
            $p   = Product::where('is_active', 1)->find($pid);

            if (!$p) {
                $this->telegram->answerCallbackQuery($id, "Товар не найден", true);
                return;
            }

            $qty = $this->addToCart($user, $pid);
            $this->telegram->answerCallbackQuery(
                $id,
                "Добавлено в корзину ({$qty} шт.)",
                false
            );
            return;
        }

        $this->telegram->answerCallbackQuery($id);
    }

    /* ==========================
     *   Главное меню и каталог
     * ========================== */

    protected function sendMainMenu($chat)
    {
        $kb = [
            'keyboard' => [
                [
                    ['text' => '🛍 Каталог'],
                    ['text' => '🧺 Корзина'],
                ],
                [
                    ['text' => '📦 Мои заказы'],
                    ['text' => 'ℹ Помощь'],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];

        $this->telegram->sendMessage($chat, "Привет! Выбери действие:", [
            'reply_markup' => json_encode($kb),
        ]);
    }

    protected function sendProducts($chat)
    {
        $items = Product::where('is_active', 1)->get();

        if ($items->isEmpty()) {
            $this->telegram->sendMessage($chat, "Нет доступных товаров.");
            return;
        }

        $buttons = [];
        foreach ($items as $i) {
            $buttons[] = [[
                'text'          => "{$i->title} ({$i->price})",
                'callback_data' => "product:{$i->id}",
            ]];
        }

        $this->telegram->sendMessage($chat, "Выберите товар:", [
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /* ==========================
     *   Корзина
     * ========================== */

    protected function getCart(TelegramUser $user): array
    {
        return $user->cart ?? [];
    }

    protected function saveCart(TelegramUser $user, array $cart): void
    {
        $user->cart = $cart;
        $user->save();
    }

    protected function addToCart(TelegramUser $user, int $productId): int
    {
        $cart = $this->getCart($user);
        $cart[$productId] = ($cart[$productId] ?? 0) + 1;
        $this->saveCart($user, $cart);

        return $cart[$productId];
    }

    protected function sendCart(TelegramUser $user, int $chat): void
    {
        $cart = $this->getCart($user);
        if (empty($cart)) {
            $this->telegram->sendMessage($chat, "Ваша корзина пуста.");
            return;
        }

        $productIds = array_keys($cart);
        $products = Product::whereIn('id', $productIds)
            ->where('is_active', 1)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            $this->saveCart($user, []);
            $this->telegram->sendMessage($chat, "Товары из корзины больше недоступны.");
            return;
        }

        $lines = [];
        $total = 0;

        foreach ($cart as $pid => $qty) {
            if (!isset($products[$pid])) {
                continue;
            }
            $p = $products[$pid];
            $lineTotal = $p->price * $qty;
            $total += $lineTotal;
            $lines[] = "• {$p->title} x {$qty} = <b>{$lineTotal}</b>";
        }

        if (empty($lines)) {
            $this->saveCart($user, []);
            $this->telegram->sendMessage($chat, "Товары из корзины больше недоступны.");
            return;
        }

        $text =
            "🧺 <b>Ваша корзина</b>\n\n" .
            implode("\n", $lines) .
            "\n\nИтого: <b>{$total}</b>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text'          => '✅ Оформить заказ',
                        'callback_data' => 'cart_checkout',
                    ],
                ],
                [
                    [
                        'text'          => '🗑 Очистить',
                        'callback_data' => 'cart_clear',
                    ],
                    [
                        'text'          => '⬅ Каталог',
                        'callback_data' => 'catalog',
                    ],
                ],
            ],
        ];

        $this->telegram->sendMessage($chat, $text, [
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /* ==========================
     *   Оформление заказа (телефон)
     * ========================== */

    protected function handleContactInput(TelegramUser $user, int $chat, string $text): void
    {
        $phone = $text;

        // Примитивная проверка
        if (mb_strlen($phone) < 5) {
            $this->telegram->sendMessage(
                $chat,
                "Похоже, это не номер телефона 😅\n" .
                "Пожалуйста, отправьте корректный номер."
            );
            return;
        }

        $payload = $user->state_payload ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $cart = $this->getCart($user);

        // Если есть корзина — создаём заказ по корзине
        if (!empty($cart)) {
            $this->createOrderFromCart($user, $chat, $phone, $cart);
            return;
        }

        // Фоллбек: старый вариант (один товар в state_payload)
        $productId = $payload['product_id'] ?? null;
        $qty       = (int) ($payload['qty'] ?? 1);

        if (!$productId || $qty < 1) {
            $user->state = 'main_menu';
            $user->state_payload = null;
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "Произошла ошибка при оформлении заказа. Попробуйте снова через каталог."
            );
            return;
        }

        $product = Product::where('is_active', 1)->find($productId);

        if (!$product) {
            $user->state = 'main_menu';
            $user->state_payload = null;
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "К сожалению, этот товар больше недоступен."
            );
            return;
        }

        $order = Order::create([
            'telegram_user_id' => $user->id,
            'status'           => 'new',
            'contact_phone'    => $phone,
            'contact_name'     => $user->first_name ?? null,
            'total_price'      => $product->price * $qty,
            'meta'             => [
                'telegram_id' => $user->telegram_id,
                'username'    => $user->username,
            ],
        ]);

        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'qty'        => $qty,
            'price'      => $product->price,
            'total'      => $product->price * $qty,
        ]);

        // Сброс состояния и корзины
        $this->saveCart($user, []);
        $user->state = 'main_menu';
        $user->state_payload = null;
        $user->save();

        $this->telegram->sendMessage(
            $chat,
            "Спасибо! 🙌\n" .
            "Ваш заказ №{$order->id} принят.\n\n" .
            "Товар: <b>{$product->title}</b>\n" .
            "Сумма: <b>{$order->total_price}</b>\n" .
            "Телефон: <b>{$order->contact_phone}</b>\n\n" .
            "Мы свяжемся с вами для уточнения деталей."
        );

        $this->notifyManager($order);
    }

    protected function createOrderFromCart(TelegramUser $user, int $chat, string $phone, array $cart): void
    {
        $productIds = array_keys($cart);
        $products = Product::whereIn('id', $productIds)
            ->where('is_active', 1)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            $this->saveCart($user, []);
            $user->state = 'main_menu';
            $user->state_payload = null;
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "Товары из корзины больше недоступны. Попробуйте выбрать заново."
            );
            return;
        }

        $total = 0;
        $lines = [];

        // Считаем общую сумму
        foreach ($cart as $pid => $qty) {
            if (!isset($products[$pid]) || $qty < 1) {
                continue;
            }
            $p = $products[$pid];
            $lineTotal = $p->price * $qty;
            $total += $lineTotal;
            $lines[] = [$p, $qty, $lineTotal];
        }

        if (empty($lines)) {
            $this->saveCart($user, []);
            $user->state = 'main_menu';
            $user->state_payload = null;
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "Товары из корзины больше недоступны. Попробуйте выбрать заново."
            );
            return;
        }

        $order = Order::create([
            'telegram_user_id' => $user->id,
            'status'           => 'new',
            'contact_phone'    => $phone,
            'contact_name'     => $user->first_name ?? null,
            'total_price'      => $total,
            'meta'             => [
                'telegram_id' => $user->telegram_id,
                'username'    => $user->username,
            ],
        ]);

        foreach ($lines as [$p, $qty, $lineTotal]) {
            OrderItem::create([
                'order_id'   => $order->id,
                'product_id' => $p->id,
                'qty'        => $qty,
                'price'      => $p->price,
                'total'      => $lineTotal,
            ]);
        }

        // Сброс корзины и состояния
        $this->saveCart($user, []);
        $user->state = 'main_menu';
        $user->state_payload = null;
        $user->save();

        $summaryLines = array_map(function ($row) {
            /** @var \App\Models\Product $p */
            [$p, $qty, $lineTotal] = $row;
            return "• {$p->title} x {$qty} = <b>{$lineTotal}</b>";
        }, $lines);

        $text =
            "Спасибо! 🙌\n" .
            "Ваш заказ №{$order->id} принят.\n\n" .
            implode("\n", $summaryLines) .
            "\n\nИтого: <b>{$order->total_price}</b>\n" .
            "Телефон: <b>{$order->contact_phone}</b>\n\n" .
            "Мы свяжемся с вами для уточнения деталей.";

        $this->telegram->sendMessage($chat, $text);
        $this->notifyManager($order);
    }

    /* ==========================
     *   История заказов
     * ========================== */

    protected function sendOrdersList(TelegramUser $user, int $chat): void
    {
        $orders = Order::where('telegram_user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chat, "У вас пока нет заказов.");
            return;
        }

        $lines = [];
        foreach ($orders as $order) {
            $date = $order->created_at?->format('d.m H:i');
            $lines[] = "№{$order->id} — {$order->status}, {$order->total_price}, {$date}";
        }

        $text =
            "📦 <b>Ваши последние заказы</b>:\n\n" .
            implode("\n", $lines) .
            "\n\nДля нового заказа откройте каталог: «🛍 Каталог».";

        $this->telegram->sendMessage($chat, $text);
    }

    /* ==========================
     *   Уведомление менеджеру
     * ========================== */

    protected function notifyManager(Order $order): void
    {
        $managerChatId = config('services.telegram.manager_chat_id');

        if (!$managerChatId) {
            return;
        }

        $order->loadMissing('items.product', 'user');

        $user = $order->user;
        $lines = [];

        foreach ($order->items as $item) {
            $title = $item->product?->title ?? ('ID ' . $item->product_id);
            $lines[] = "• {$title} x {$item->qty} = {$item->total}";
        }

        $text =
            "🔔 <b>Новый заказ №{$order->id}</b>\n\n" .
            "Клиент: " .
            ($user?->first_name ? $user->first_name . ' ' : '') .
            "(TG: @" . ($user?->username ?? $user?->telegram_id) . ")\n" .
            "Телефон: {$order->contact_phone}\n" .
            "Сумма: {$order->total_price}\n\n" .
            "Позиции:\n" .
            implode("\n", $lines);

        $this->telegram->sendMessage((int) $managerChatId, $text);
    }
}
