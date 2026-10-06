<?php

declare(strict_types=1);

namespace App;

use Pam\Native\Component;
use Pam\Native\Element;
use Pam\Native\Style;
use Pam\Native\UI\Button;
use Pam\Native\UI\Column;
use Pam\Native\UI\Row;
use Pam\Native\UI\SafeAreaView;
use Pam\Native\UI\Screen;
use Pam\Native\UI\Text;
use Pam\Native\UI\TextInput;
use Pam\Nitro\Batch;
use Pam\Nitro\Nitro;

/**
 * Offline notes on PAM Native Nitro: one boot + prepare, lazy bounded
 * queries, atomic batches and a scoped replaceMany() "sync from server".
 */
final class OfflineNotes extends Component
{
    private bool $ready = false;
    private string $draft = '';
    private string $error = '';

    /** @var list<Note> */
    private array $notes = [];

    public function boot(): void
    {
        Nitro::onFailure(function (string $message): void {
            $this->error = $message;
        });
        Nitro::boot('notes-demo.db');
        Nitro::prepare([Note::class], function (): void {
            $this->ready = true;
            $this->reload();
        });
    }

    public function render(): Element
    {
        $rows = array_map(fn (Note $note): Row => Row::make(
            Text::make(($note->pinned ? '📌 ' : '').$note->body)->style(new Style(flexGrow: 1)),
            Button::make('Delete')->onPress(fn () => $this->delete($note)),
        )->style(new Style(gap: 8)), $this->notes);

        return Screen::make(
            SafeAreaView::make(
                Column::make(
                    Text::make('Offline notes')->style(new Style(fontSize: 24, fontWeight: 700)),
                    Text::make($this->ready ? count($this->notes).' notes (newest 50)' : 'Preparing schema…'),
                    TextInput::make($this->draft)->placeholder('Write a note')->onChange(function (string $value): void {
                        $this->draft = $value;
                    }),
                    ...[
                        Row::make(
                            Button::make('Add')->onPress($this->add(...)),
                            Button::make('Pin all + add 3')->onPress($this->batchDemo(...)),
                            Button::make('Replace from "server"')->onPress($this->replaceFromServer(...)),
                        )->style(new Style(gap: 8)),
                        $this->error !== '' ? Text::make('Nitro: '.$this->error) : null,
                        ...$rows,
                    ],
                )->style(new Style(flexGrow: 1, padding: 16, gap: 10)),
            ),
        );
    }

    public function add(): void
    {
        if (!$this->ready || trim($this->draft) === '') {
            return;
        }
        $note = self::note(trim($this->draft));
        $this->draft = '';
        $note->save(fn () => $this->reload(), fn (string $error) => $this->error = $error);
    }

    public function delete(Note $note): void
    {
        $note->delete(fn () => $this->reload());
    }

    public function batchDemo(): void
    {
        if (!$this->ready) {
            return;
        }
        // One native call, one transaction.
        Nitro::batch(function (Batch $batch): void {
            $batch->execute('UPDATE "notes" SET "pinned" = 1 WHERE "folder" = ?', ['inbox'])
                ->saveMany([self::note('Batch A'), self::note('Batch B'), self::note('Batch C', NoteColor::Green)]);
        }, fn () => $this->reload());
    }

    public function replaceFromServer(): void
    {
        if (!$this->ready) {
            return;
        }
        // The whole "inbox" folder is replaced atomically: no stale rows, no empty window.
        $fresh = [self::note('From server 1'), self::note('From server 2', NoteColor::Yellow)];
        Nitro::replaceMany(Note::class, $fresh, ['folder' => 'inbox'], fn () => $this->reload());
    }

    private function reload(): void
    {
        Note::query()
            ->where('folder', 'inbox')
            ->orderBy('pinned', descending: true)
            ->limit(50)
            ->get(function (array $notes): void {
                $this->notes = $notes;
            });
    }

    private static function note(string $body, NoteColor $color = NoteColor::Plain): Note
    {
        $note = new Note();
        $note->id = bin2hex(random_bytes(8));
        $note->body = $body;
        $note->color = $color;
        $note->createdAt = (int) (microtime(true) * 1000);

        return $note;
    }
}
