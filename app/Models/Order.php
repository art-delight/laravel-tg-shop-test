<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'status',
        'contact_phone',
        'contact_name',
        'total_price',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(TelegramUser::class, 'telegram_user_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
