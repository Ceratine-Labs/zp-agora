<?php

namespace Modules\StockRecon\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Support\ShiftLabel;
use RuntimeException;

/**
 * "Explain this chain" (Ryan, 15 Nov 2026: "give a button to explain the run
 * in the same way you did now").
 *
 * The chain behind one proposal, as agora.usp_StockRecon_DrillChain returns
 * it, plus the run's till gaps for that area and item, written out as plain
 * facts and sent to Claude with the method (Resources/prompts/explain-chain.md).
 * Claude reads and explains; it decides nothing and nothing it says is
 * written anywhere but the screen.
 *
 * The answer is cached against a hash of the exact facts, so a second press,
 * or a colleague's, costs nothing, and a new preview (new figures) asks again.
 */
class ChainExplainer
{
    public function __construct(private readonly StockReconService $recon) {}

    public function available(): bool
    {
        return filled(config('services.anthropic.key')) || filled(config('services.ollama.key'));
    }

    /**
     * @return array{text: string, by: string}
     */
    public function explain(StockReconRun $run, StockReconRunLine $line): array
    {
        if (! $this->available()) {
            throw new RuntimeException('Chain explanations are not switched on: there is no Anthropic or Ollama key.');
        }

        $chain = $this->recon->chain($run, $line);
        $facts = $this->facts($run, $chain['item'], $chain['shifts'], $this->recon->cachedTillQtyGaps($run));
        $config = config('stockrecon.explain');

        return Cache::remember(
            'stockrecon:explain:'.sha1($config['model'].'|'.$config['fallback']['model'].'|'.$this->system().'|'.$facts),
            now()->addHours((int) $config['cache_hours']),
            fn () => $this->answer($facts),
        );
    }

    /**
     * Claude first; Ollama Cloud while ZP's Anthropic account has no credit
     * (Ryan, 15 Nov 2026: "if there is no credit on the acc, please use the
     * ollama key for now"). A topped-up account takes over again by itself.
     *
     * @return array{text: string, by: string}
     */
    protected function answer(string $facts): array
    {
        if (filled(config('services.anthropic.key'))) {
            try {
                return ['text' => $this->ask($facts), 'by' => 'Claude'];
            } catch (RuntimeException $e) {
                if (! $e->getPrevious() instanceof BadRequestException && ! $e->getPrevious() instanceof AuthenticationException) {
                    throw $e;
                }
                if (blank(config('services.ollama.key'))) {
                    throw $e;
                }
            }
        }

        return ['text' => $this->askOllama($facts), 'by' => config('stockrecon.explain.fallback.model').' (Ollama Cloud)'];
    }

    /**
     * Ollama Cloud's OpenAI-compatible endpoint. Its reasoning models spend
     * tokens thinking first; at medium effort glm-5.3 thought past 16,000
     * tokens and a minute on a six-shift chain, at low it answered in five
     * seconds (measured 15 Nov 2026), so low it is.
     */
    protected function askOllama(string $facts): string
    {
        $cfg = config('stockrecon.explain.fallback');

        $response = Http::timeout((int) $cfg['timeout'])
            ->acceptJson()
            ->withToken((string) config('services.ollama.key'))
            ->post(rtrim((string) $cfg['base_url'], '/').'/chat/completions', [
                'model' => $cfg['model'],
                'max_tokens' => (int) $cfg['max_tokens'],
                'reasoning_effort' => $cfg['reasoning_effort'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->system()],
                    ['role' => 'user', 'content' => "Explain this chain.\n\n".$facts],
                ],
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('The explanation service is busy just now. Try again in a minute.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('The explanation service refused the request ('.$response->status().'). Try again later.');
        }

        $text = trim((string) $response->json('choices.0.message.content'));
        if ($text === '') {
            throw new RuntimeException('The explanation came back empty. Try again in a moment.');
        }

        return $text;
    }

    public function system(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Resources/prompts/explain-chain.md');
    }

    /**
     * The chain as text: the item, its totals and holds, the till gaps that
     * touch it, then one line per shift, counted beside proposed.
     *
     * @param  Collection<int, object>  $shifts
     * @param  Collection<int, \stdClass>  $tillGaps
     */
    public function facts(StockReconRun $run, ?object $item, Collection $shifts, Collection $tillGaps): string
    {
        $n = fn ($v) => $v === null ? '-' : Format::qty($v);
        $day = fn ($d) => Carbon::parse($d)->format('D j M');

        $lines = [];
        $lines[] = 'Run #'.$run->Id.', '.$day($run->FromDate).' to '.$day($run->ToDate).'.';

        if ($item !== null) {
            $lines[] = 'Item: '.$item->StockItemDescription.' (item '.$item->StockItemNo.', unit '.($item->UOMCode ?? '?').')'
                .', counting area '.$item->AreaDescription.' (area '.$item->AreaNo.')'
                .', sold through till location '.($item->StockLocation ?: 'unknown').'.';
            $lines[] = 'Issue multiple (pack size): '.$n($item->IssueMultiple).'.';
            $lines[] = 'Chain total as counted: '.$n($item->ChainNetVar).' ('.Format::r($item->ChainNetVarValue).').';
            $lines[] = 'Held back: '.($item->ChainBlocked ? 'yes' : 'no').'.';

            $reasons = collect((array) $item)
                ->filter(fn ($v, $k) => str_starts_with((string) $k, 'Block') && is_string($v) && $v !== '' && ! is_numeric($v))
                ->values();
            if ($reasons->isNotEmpty()) {
                $lines[] = 'Checks that fired on this chain: '.$reasons->implode('; ').'.';
            }
        }

        $gaps = $tillGaps->filter(fn ($g) => $item !== null && (string) $g->AreaNo === (string) $item->AreaNo
            && ($g->Kind === 'feed' || (string) $g->StockItemNo === (string) $item->StockItemNo));
        foreach ($gaps as $g) {
            $lines[] = $g->Kind === 'feed'
                ? 'MISSING TILL FEED: no sales at all from the '.$g->Location.' till on '.$day($g->TransactionDate).' (PumpIT has none for that day).'
                : 'MISSING TILL QUANTITY on '.$day($g->TransactionDate).': recon POS '.$n($g->QtyRecon).', tills sold '.$n($g->QtyTill).'.';
        }

        $lines[] = '';
        $lines[] = 'Shifts in order. AS COUNTED: open, issued, close, POS, variance, value, cumulative. AS AGORA PROPOSES: open, issued, close, variance, value, cumulative. Then the amendment to the closing, and Agora\'s outcome.';
        foreach ($shifts as $s) {
            $lines[] = '- '.$day($s->TransactionDate).' shift '.ShiftLabel::show($s->ShiftNo)
                .($s->IsDormant ? ' [dormant]' : '')
                .' | counted: open '.$n($s->QtyOpen).', issued '.$n($s->QtyIssued).', close '.$n($s->QtyClose)
                .', POS '.$n($s->QtyPOS).', var '.$n($s->QtyVar).' ('.Format::r($s->VarValue).'), cum '.$n($s->CumulativeVar)
                .' | proposed: open '.$n($s->QtyOpenNew).', issued '.$n($s->QtyIssuedNew ?? $s->QtyIssued).', close '.$n($s->QtyCloseNew)
                .', var '.$n($s->QtyVarNew).' ('.Format::r($s->VarValueNew).'), cum '.$n($s->CumulativeVarNew)
                .' | close amended by '.$n($s->AmendClose)
                .' | outcome: '.trim((string) $s->Outcome)
                .($s->EmployeeCount ? ' | on shift: '.$s->EmployeeCount : '');
        }

        return implode("\n", $lines);
    }

    /**
     * One request to Claude. Adaptive thinking is always on for this model;
     * effort sets how hard it works. The method is the stable, cached prefix.
     */
    protected function ask(string $facts): string
    {
        $config = config('stockrecon.explain');
        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $client->messages->create(
                model: $config['model'],
                maxTokens: (int) $config['max_tokens'],
                system: [
                    ['type' => 'text', 'text' => $this->system(), 'cacheControl' => ['type' => 'ephemeral']],
                ],
                outputConfig: ['effort' => $config['effort']],
                messages: [
                    ['role' => 'user', 'content' => "Explain this chain.\n\n".$facts],
                ],
            );
        } catch (BadRequestException $e) {
            // The one 400 a reader can do something about: the account is out of credit.
            if (str_contains($e->getMessage(), 'credit balance')) {
                throw new RuntimeException('The Anthropic account behind ZP\'s key has no credit left. Ask head office to top it up, then try again.', 0, $e);
            }
            throw $e;
        } catch (AuthenticationException $e) {
            throw new RuntimeException('ZP\'s Anthropic key was refused. Ask head office to check it.', 0, $e);
        } catch (RateLimitException $e) {
            throw new RuntimeException('Anthropic is busy just now. Try again in a minute.', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The explanation was declined. Read the chain from the grid instead.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('The explanation came back empty. Try again in a moment.');
        }

        return $text;
    }
}
