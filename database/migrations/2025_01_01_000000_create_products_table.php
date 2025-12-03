<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(){
        Schema::create('products',function(Blueprint $t){
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->decimal('price',10,2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
    public function down(){ Schema::dropIfExists('products'); }
};
