<?php

declare(strict_types=1);

namespace Thallo\Importers\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Importers\Markdown\MarkdownFolderImport;

/**
 * `thallo:import:markdown <folder>` — a folder of Markdown as the pages of a content type, for a
 * deploy script to run ({@see MarkdownFolderImport}). Exits non-zero when any file failed, so a
 * deploy stops on a page that did not import; a file that is gone is only ever reported.
 */
#[AsCommand(
    name: 'thallo:import:markdown',
    description: 'Import a folder of Markdown as the pages of a content type (repeatable)',
)]
final class ImportMarkdownCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                "Imports every .md file under <folder> into a content type, keeping the Markdown as\n"
                . "written. Run it on every deploy: a file lands on the page it made last time, only\n"
                . "changed pages are written, a changed slug leaves a redirect, nothing is deleted.\n\n"
                . "  thallo:docs:setup                                        once: make the \"docs\" type\n"
                . "  thallo:import:markdown docs --type=docs --publish\n"
                . "  thallo:import:markdown docs --type=docs --dry-run         say what would change\n"
                . "  thallo:import:markdown docs --type=docs --exclude=internal --exclude=drafts\n\n"
                . "Front matter (all optional): title, slug, section, order, summary, draft: true.\n"
                . "Without it: the slug is the file's name (README/index is its folder), an NN- prefix is\n"
                . "the order, the top folder is the section, the first # heading is the title.\n"
                . 'Links between .md files become links between the pages.'
            )
            ->addArgument('folder', InputArgument::REQUIRED, 'The folder of Markdown files')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The content type to import into', 'docs')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The locale the pages are in', 'en')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish each page (otherwise: drafts)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change; write nothing')
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A folder to leave out, by name or path (repeatable)',
            )
            ->addOption(
                'edit-base',
                null,
                InputOption::VALUE_REQUIRED,
                'URL prefix for each page\'s "edit this page" link',
            )
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'User uuid the writes are attributed to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var MarkdownFolderImport $import */
        $import = $this->getService(MarkdownFolderImport::class);
        try {
            $report = $import->run((string) $input->getArgument('folder'), [
                'type' => (string) $input->getOption('type'),
                'locale' => (string) $input->getOption('locale'),
                'publish' => (bool) $input->getOption('publish'),
                'dry_run' => (bool) $input->getOption('dry-run'),
                'exclude' => array_values(array_map('strval', (array) $input->getOption('exclude'))),
                'edit_base' => $input->getOption('edit-base'),
                'actor' => $input->getOption('actor'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($report['files'] as $file) {
            $line = sprintf('  %-10s %s  →  /%s/%s', $file['status'], $file['path'], $report['type'], $file['slug']);
            $file['status'] === 'failed' ? $this->error($line) : $this->line($line);
            if (isset($file['message'])) {
                $this->line('             ' . $file['message']);
            }
            foreach ($file['broken_links'] ?? [] as $link) {
                $this->warning("             links to {$link}, which is not in the import");
            }
        }
        foreach ($report['missing'] as $gone) {
            $this->warning(
                "  gone       {$gone['source_path']} — its page ({$gone['entry']}) is still there; "
                . 'remove it in the admin',
            );
        }

        $held = array_filter($report['files'], static fn (array $f): bool => ($f['publish_held'] ?? false) === true);
        if ($held !== []) {
            $this->warning('  Pass --actor=<user uuid> for a user allowed to bypass review.');
        }

        $c = $report['counts'];
        $summary = sprintf(
            '%s%d created, %d updated, %d unchanged, %d skipped, %d failed.',
            $report['dry_run'] ? 'Dry run: ' : '',
            $c['created'],
            $c['updated'],
            $c['unchanged'],
            $c['skipped'],
            $c['failed'],
        );
        if ($c['failed'] > 0) {
            $this->error($summary);
            return self::FAILURE;
        }
        $this->success($summary);
        return self::SUCCESS;
    }
}
