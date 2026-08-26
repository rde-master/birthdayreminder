<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Command;

use DateTimeImmutable;
use OCA\BirthdayReminder\Contacts\ContactsGateway;
use OCA\BirthdayReminder\Service\ReminderCalculator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Debug-only console command for M1: dumps upcoming birthday matches without
 * touching the database or sending mail. Not wired into the daily job.
 *
 * Usage: occ birthdayreminder:debug-upcoming <addressbook-owner> [--book-id=N] [--offsets=30,14,2,1,0]
 */
class DebugUpcoming extends Command {
    public function __construct(
        private ContactsGateway $contactsGateway,
        private ReminderCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('birthdayreminder:debug-upcoming')
            ->setDescription('Print upcoming birthday matches for a given address book (no DB, no mail)')
            ->addArgument('owner', InputArgument::REQUIRED, 'Nextcloud user id owning the address book')
            ->addOption('book-id', null, InputOption::VALUE_REQUIRED, 'Address book id (default: first book found for owner)')
            ->addOption('offsets', null, InputOption::VALUE_REQUIRED, 'Comma-separated days-before values', '30,14,2,1,0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $owner = $input->getArgument('owner');
        $books = $this->contactsGateway->getAddressBooksForOwner($owner);

        if (empty($books)) {
            $output->writeln("<error>No address books found for user '{$owner}'.</error>");
            return 1;
        }

        $bookIdOption = $input->getOption('book-id');
        $bookId = $bookIdOption !== null ? (int)$bookIdOption : (int)array_key_first($books);

        if (!isset($books[$bookId])) {
            $output->writeln("<error>Address book id {$bookId} not found for user '{$owner}'.</error>");
            $this->listBooks($books, $output);
            return 1;
        }

        $output->writeln(sprintf(
            'Using address book "%s" (id %d) owned by %s',
            $books[$bookId]['displayName'],
            $bookId,
            $owner
        ));

        $members = $this->contactsGateway->getMembers($bookId);
        $output->writeln(sprintf('%d contact(s) with a parseable BDAY found.', count($members)));

        $offsets = array_map('intval', explode(',', (string)$input->getOption('offsets')));
        $today = new DateTimeImmutable('today');
        $matches = $this->calculator->findMatches($members, $offsets, $today);

        if (empty($matches)) {
            $output->writeln('No matches for the given offsets.');
            return 0;
        }

        usort($matches, static fn ($a, $b) => $a['daysBefore'] <=> $b['daysBefore']);

        foreach ($matches as $match) {
            $member = $match['member'];
            $ageInfo = $match['age'] !== null ? sprintf(', wird %d', $match['age']) : ', Alter unbekannt';
            $output->writeln(sprintf(
                '%3d Tag(e) vorher -> %s (%s)%s [%s]',
                $match['daysBefore'],
                $member->displayName,
                $member->email ?? 'keine E-Mail',
                $ageInfo,
                $match['targetDate']->format('Y-m-d')
            ));
        }

        return 0;
    }

    /**
     * @param array<int, array{id: int, uri: string, displayName: string}> $books
     */
    private function listBooks(array $books, OutputInterface $output): void {
        $output->writeln('Available address books:');
        foreach ($books as $book) {
            $output->writeln(sprintf('  id=%d  %s (%s)', $book['id'], $book['displayName'], $book['uri']));
        }
    }
}
