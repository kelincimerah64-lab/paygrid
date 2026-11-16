<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Runs the user's question through the restricted "ops-assistant" Claude
 * Code project (separate repo, read-only + create-merchant only - see its
 * own CLAUDE.md) and sends the answer back over WhatsApp. One claude session
 * per WA chat is kept (cached by chat id) so follow-up questions in the same
 * conversation keep context, the same way the web chat widget does.
 */
class AnswerWhatsAppAssistantQuestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly string $chatId,
        public readonly string $message,
    ) {}

    public function handle(WhatsAppNotifier $wa): void
    {
        $cacheKey = 'wa-assistant-claude-session:'.$this->chatId;
        $resumeSessionId = Cache::get($cacheKey);

        $args = ['claude', '-p', '--permission-mode', 'dontAsk', '--output-format', 'json'];
        if ($resumeSessionId) {
            $args[] = '--resume';
            $args[] = $resumeSessionId;
        }
        $args[] = $this->message;

        $result = Process::path(config('paygrid.ops_assistant_cli.working_directory'))
            ->timeout(80)
            ->env([
                'OPS_ASSISTANT_TOKEN' => config('services.ops_assistant.token'),
                'PATH' => config('paygrid.ops_assistant_cli.path'),
            ])
            ->run($args);

        if (! $result->successful()) {
            Log::warning('paygrid.wa_assistant.process_failed', [
                'chat_id' => $this->chatId,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);
            $wa->send($this->chatId, 'Maaf, ada gangguan teknis pas proses pertanyaan kamu. Coba lagi sebentar lagi.');

            return;
        }

        $decoded = json_decode($result->output(), true);
        $answer = trim((string) ($decoded['result'] ?? ''));
        $sessionId = $decoded['session_id'] ?? null;

        if ($sessionId) {
            Cache::put($cacheKey, $sessionId, now()->addDay());
        }

        if ($answer === '') {
            Log::warning('paygrid.wa_assistant.empty_answer', ['chat_id' => $this->chatId, 'raw' => $result->output()]);
            $wa->send($this->chatId, 'Maaf, gak dapet jawaban. Coba tanya ulang dengan kalimat lain.');

            return;
        }

        $wa->send($this->chatId, $answer);
    }
}
