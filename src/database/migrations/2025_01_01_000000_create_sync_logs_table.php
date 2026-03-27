<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('table');
            $table->uuid('uuid');
            $table->enum('action', ['create', 'update', 'delete']);
            $table->string('client_id')->nullable();
            $table->enum('status', ['pending', 'synced', 'conflict', 'error'])
                ->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sync_logs');
    }
};
