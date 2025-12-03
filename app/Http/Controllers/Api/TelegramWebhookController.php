<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller {
    public function __construct(protected TelegramBotService $telegram){}

    public function handle(Request $request,$secret){
        if($secret !== env('TELEGRAM_BOT_WEBHOOK_SECRET')) abort(403);
        $upd=$request->all();

        if(isset($upd['message'])) $this->handleMessage($upd['message']);
        if(isset($upd['callback_query'])) $this->handleCallback($upd['callback_query']);
        return response()->json(['ok'=>true]);
    }

    protected function getUser($from){
        return TelegramUser::updateOrCreate(
            ['telegram_id'=>$from['id']],
            [
                'username'=>$from['username']??null,
                'first_name'=>$from['first_name']??null,
                'last_name'=>$from['last_name']??null
            ]
        );
    }

    protected function handleMessage($m){
        $chat=$m['chat']['id'];
        $text=trim($m['text']??'');
        $user=$this->getUser($m['from']);

        if(str_starts_with($text,'/start')){
            $user->update(['state'=>'main_menu','state_payload'=>null]);
            return $this->sendMainMenu($chat);
        }

        switch($text){
            case '🛍 Каталог':
                $user->update(['state'=>'browse']);
                $this->sendProducts($chat);
                break;
            case 'ℹ Помощь':
                $this->telegram->sendMessage($chat,"Доступные команды:
/start — меню
Каталог — список товаров");
                break;
            default:
                $this->telegram->sendMessage($chat,"Не понял. Используй /start");
        }
    }

    protected function handleCallback($cb){
        $id=$cb['id'];
        $data=$cb['data']??'';
        $chat=$cb['message']['chat']['id'];

        if(str_starts_with($data,'product:')){
            $pid=(int)str_replace('product:','',$data);
            $p=Product::find($pid);
            if(!$p){ $this->telegram->answerCallbackQuery($id,"Товар не найден",true); return;}
            $txt="<b>{$p->title}</b>
{$p->description}
Цена: {$p->price}";
            $this->telegram->sendMessage($chat,$txt);
            $this->telegram->answerCallbackQuery($id,"Открываю");
        }
    }

    protected function sendMainMenu($chat){
        $kb=['keyboard'=>[[['text'=>'🛍 Каталог'],['text'=>'ℹ Помощь']]],'resize_keyboard'=>true];
        $this->telegram->sendMessage($chat,"Привет! Выбери действие:",['reply_markup'=>json_encode($kb)]);
    }

    protected function sendProducts($chat){
        $items=Product::where('is_active',1)->get();
        if($items->isEmpty()) {
            $this->telegram->sendMessage($chat,"Нет товаров."); return;
        }
        $buttons=[];
        foreach($items as $i){
            $buttons[]=[[ 'text'=> "{$i->title} ({$i->price})", 'callback_data'=>"product:$i->id" ]];
        }
        $this->telegram->sendMessage($chat,"Товары:",[
            'reply_markup'=>json_encode(['inline_keyboard'=>$buttons])
        ]);
    }
}
