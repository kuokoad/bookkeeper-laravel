<?php

declare(strict_types=1);

namespace App\Livewire\Help;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The help pages, served from the shop's own machine (no internet needed):
 * the old lib/help.ts. Only the pages written for a shop owner, from
 * resources/docs. The slug picks an entry here, never a file path, so no
 * address can reach a file that was not meant to be served.
 */
final class Pages
{
    /** slug => [title, blurb, file under resources/docs] */
    public const PAGES = [
        'getting-started' => ['Getting started', 'Set the shop up, put something on the shelf, sell it, and see what it earned.', 'tutorials/first-hour.md'],
        'finding-things' => ['Finding things', 'Narrow any list to exactly what you want, and take it away as a spreadsheet.', 'how-to/find-anything.md'],
        'quoting' => ['Quoting a customer', 'Give a contractor a price they can take away, then turn it into a sale without typing it again.', 'how-to/quote-a-customer.md'],
        'fixing-a-mistake' => ['Fixing a mistake', 'Undo a wrong sale, take goods back, or correct a count — without deleting anything.', 'how-to/fix-a-mistake.md'],
        'credit-and-payments' => ['Buying and selling on credit', 'Take goods before paying, sell to customers who pay later, and see which invoice a payment settles.', 'how-to/credit-and-payments.md'],
        'moving-money' => ['Moving money between accounts', "Bank the day's cash, or move money between Cash, MoMo and the bank without touching profit.", 'how-to/transfer-between-accounts.md'],
        'backdating-a-sale' => ['Recording a sale on an earlier day', 'Enter paper receipts on the day they happened, and what the till refuses.', 'how-to/backdate-a-sale.md'],
        'managing-tax' => ['Managing tax', "Set up Ghana's three taxes, and change a rate when the budget moves one.", 'how-to/manage-tax.md'],
        'closing-a-period' => ['Closing a period', 'Lock a month once it is filed, close a year, and produce the pack your accountant asks for.', 'how-to/close-a-period.md'],
        'backups' => ['Backups', 'The routine that keeps the books safe, and what to do on a bad day.', 'how-to/back-up-and-restore.md'],
    ];

    /**
     * One page as HTML, or null when its file is missing. Raw HTML in the
     * Markdown is escaped, unsafe links dropped, the document's own H1
     * removed (the page shows the title), and a link to another served page
     * becomes /help/<slug>; a link to anything else keeps its words but
     * loses its href, rather than leading somebody at a till to a 404.
     */
    public static function render(string $slug): ?string
    {
        $file = resource_path('docs/'.self::PAGES[$slug][2]);
        if (! is_file($file)) {
            return null;
        }

        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $firstHeadingDropped = false;
            foreach (iterator_to_array($event->getDocument()->iterator(), false) as $node) {
                if (! $firstHeadingDropped && $node instanceof Heading && $node->getLevel() === 1) {
                    $node->detach();
                    $firstHeadingDropped = true;
                } elseif ($node instanceof Link) {
                    $href = self::resolveLink($node->getUrl());
                    if ($href !== null) {
                        $node->setUrl($href);
                    } else {
                        foreach ($node->children() as $child) {
                            $node->insertBefore($child);
                        }
                        $node->detach();
                    }
                }
            }
        });

        return (string) (new MarkdownConverter($environment))->convert((string) file_get_contents($file));
    }

    private static function resolveLink(string $href): ?string
    {
        if (preg_match('#^https?://#', $href) === 1 || str_starts_with($href, '/')) {
            return $href;
        }
        $target = basename(explode('#', $href)[0]);
        foreach (self::PAGES as $slug => [, , $file]) {
            if (basename($file) === $target) {
                return "/help/{$slug}";
            }
        }

        return null;
    }
}
