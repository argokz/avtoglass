<?php

declare(strict_types=1);

use App\Ai\Runtime\MessageText;
use App\Ai\Runtime\ToolPayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->text('title')->nullable()->change());
        Schema::table('ai_exchange_logs', fn (Blueprint $table) => $table->text('search_query')->nullable()->change());
        foreach (['ai_messages' => ['text'], 'ai_conversations' => ['title'], 'ai_exchange_logs' => ['question', 'answer', 'search_query']] as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $columns) {
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} !== null && $row->{$column} !== '' && ! str_starts_with($row->{$column}, 'enc:v1:')) {
                            $values[$column] = MessageText::encode($row->{$column});
                        }
                    }
                    if ($values) {
                        DB::table($table)->where('id', $row->id)->update($values);
                    }
                }
            });
        }
        DB::table('ai_tool_results')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('ai_tool_results')->where('id', $row->id)->update(['arguments' => ToolPayload::encode(ToolPayload::decode($row->arguments)), 'result' => ToolPayload::encode(ToolPayload::decode($row->result))]);
            }
        });
    }

    public function down(): void
    {
        // Retain ciphertext on rollback. The compatibility readers can still read historical plaintext.
    }
};
