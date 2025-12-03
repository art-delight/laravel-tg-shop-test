<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramBotService {
    protected string $token;
    protected string $apiUrl;

    public function __construct() {
        $this->token = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}/";
    }

    protected function request(string $method, array $params = []) {
        return Http::post($this->apiUrl . $method, $params)->json();
    }

    public function sendMessage($chatId, $text, $opt=[]) {
        return $this->request('sendMessage', array_merge([
            'chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML'
        ], $opt));
    }

    public function answerCallbackQuery($id,$text='',$alert=false){
        return $this->request('answerCallbackQuery',[
            'callback_query_id'=>$id,'text'=>$text,'show_alert'=>$alert
        ]);
    }
}
