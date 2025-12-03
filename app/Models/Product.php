<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    protected $fillable=['title','description','price','is_active'];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

}
