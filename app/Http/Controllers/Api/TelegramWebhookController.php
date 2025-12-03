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

    protected function getUser($from)
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

    protected function handleMessage($m)
    {
        $chat = $m['chat']['id'];
        $text = trim($m['text'] ?? '');
        $user = $this->getUser($m['from']);

        // 1) Если ждём телефон для оформления заказа
        if ($user->state === 'waiting_contact') {
            $this->handleContactInput($user, $chat, $text);
            return;
        }

        // 2) /start — главное меню
        if (str_starts_with($text, '/start')) {
            $user->update([
                'state'         => 'main_menu',
                'state_payload' => null,
            ]);

            return $this->sendMainMenu($chat);
        }

        // 3) Обработка основных команд
        switch ($text) {
            case '🛍 Каталог':
                $user->update(['state' => 'browse', 'state_payload' => null]);
                $this->sendProducts($chat);
                break;

            case 'ℹ Помощь':
                $this->telegram->sendMessage(
                    $chat,
                    "Доступные команды:\n" .
                    "/start — меню\n" .
                    "🛍 Каталог — список товаров"
                );
                break;

            default:
                $this->telegram->sendMessage($chat, "Не понял. Используй /start");
        }
    }

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

        // product:ID — показать товар + кнопка «Заказать»
        if (str_starts_with($data, 'product:')) {
            $pid = (int) str_replace('product:', '', $data);
            $p   = Product::find($pid);

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
                            'text'          => '🛒 Заказать',
                            'callback_data' => "order:{$p->id}",
                        ],
                    ],
                ],
            ];

            $this->telegram->sendMessage($chat, $txt, [
                'reply_markup' => json_encode($keyboard),
            ]);

            $this->telegram->answerCallbackQuery($id, "Открываю");
            return;
        }

        // order:ID — начать оформление заказа
        if (str_starts_with($data, 'order:')) {
            $pid = (int) str_replace('order:', '', $data);
            $p   = Product::where('is_active', 1)->find($pid);

            if (!$p) {
                $this->telegram->answerCallbackQuery($id, "Товар не найден", true);
                return;
            }

            // Сохраняем состояние: ждём номер телефона
            $user->state = 'waiting_contact';
            $user->state_payload = [
                'product_id' => $p->id,
                'qty'        => 1,
            ];
            $user->save();

            $this->telegram->sendMessage(
                $chat,
                "Вы хотите заказать: <b>{$p->title}</b> за <b>{$p->price}</b>.\n\n" .
                "Отправьте, пожалуйста, ваш номер телефона в ответном сообщении."
            );

            $this->telegram->answerCallbackQuery($id, "Введите телефон для оформления заказа");
            return;
        }

        $this->telegram->answerCallbackQuery($id);
    }

    protected function sendMainMenu($chat)
    {
        $kb = [
            'keyboard' => [
                [
                    ['text' => '🛍 Каталог'],
                    ['text' => 'ℹ Помощь'],
                ],
            ],
            'resize_keyboard'    => true,
            'one_time_keyboard'  => false,
        ];

        $this->telegram->sendMessage($chat, "Привет! Выбери действие:", [
            'reply_markup' => json_encode($kb),
        ]);
    }

    protected function sendProducts($chat)
    {
        $items = Product::where('is_active', 1)->get();

        if ($items->isEmpty()) {
            $this->telegram->sendMessage($chat, "Нет товаров.");
            return;
        }

        $buttons = [];
        foreach ($items as $i) {
            $buttons[] = [[
                'text'          => "{$i->title} ({$i->price})",
                'callback_data' => "product:{$i->id}",
            ]];
        }

        $this->telegram->sendMessage($chat, "Товары:", [
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /**
     * Обработка телефона от пользователя и создание заказа
     */
    protected function handleContactInput(TelegramUser $user, int $chat, string $text): void
    {
        $phone = $text;

        // Примитивная проверка — просто длина
        if (mb_strlen($phone) < 5) {
            $this->telegram->sendMessage(
                $chat,
                "Похоже, это не похоже на номер телефона 😅\n" .
                "Пожалуйста, отправьте корректный номер."
            );
            return;
        }

        $payload = $user->state_payload ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $productId = $payload['product_id'] ?? null;
        $qty       = (int) ($payload['qty'] ?? 1);

        if (!$productId || $qty < 1) {
            // что-то пошло не так — сбросим состояние
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

        // Создаём заказ
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

        // Позиция заказа
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'qty'        => $qty,
            'price'      => $product->price,
            'total'      => $product->price * $qty,
        ]);

        // Сбрасываем состояние пользователя
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
    }
}
