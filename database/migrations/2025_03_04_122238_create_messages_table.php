<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id(); // Identifiant unique
            $table->foreignId('sender_id_user')->constrained('users')->onDelete('cascade')->nullable(); // ID de l'expéditeur
            $table->foreignId('sender_id_etablisement')->constrained('etablissement')->onDelete('cascade')->nullable(); // ID de l'expéditeur
            $table->foreignId('receiver_id_user')->constrained('users')->onDelete('cascade')->nullable(); // ID du destinataire
            $table->foreignId('receiver_id_etablisement')->constrained('etablissement')->onDelete('cascade')->nullable(); // ID du destinataire
            $table->text('content'); // Contenu du message
            $table->string('file_url')->nullable(); // URL du fichier (si applicable)
            $table->enum('message_type', ['text', 'file']); // Type de message
            $table->timestamps(); // Horodatage
            $table->boolean('read')->default(false); // Indique si le message a été lu
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('messages');
    }
};
