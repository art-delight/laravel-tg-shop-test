<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')
                ->constrained('telegram_users')
                ->onDelete('cascade');

            $table->string('status')->default('new'); // new, confirmed, canceled, done
            $table->string('contact_phone')->nullable();
            $table->string('contact_name')->nullable();

            $table->decimal('total_price', 10, 2)->default(0);

            $table->json('meta')->nullable(); // доп. данные, если надо
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
