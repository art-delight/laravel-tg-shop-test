<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model {
    protected $fillable=['telegram_id','username','first_name','last_name','state','state_payload'];
    protected $casts=['state_payload'=>'array'];

    public function orders() {
        return $this->hasMany(Order::class, 'telegram_user_id');
    }

}

