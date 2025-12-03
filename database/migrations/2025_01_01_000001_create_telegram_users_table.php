<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(){
        Schema::create('telegram_users',function(Blueprint $t){
            $t->id();
            $t->bigInteger('telegram_id')->unique();
            $t->string('username')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('state')->nullable();
            $t->json('state_payload')->nullable();
            $t->timestamps();
        });
    }
    public function down(){ Schema::dropIfExists('telegram_users'); }
};
