<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Command;

use OCA\BirthdayReminder\Db\Member as MemberEntity;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Model\Member;
use OCA\BirthdayReminder\Service\ClockService;
use OCA\BirthdayReminder\Service\ReminderCalculator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Debug-only console command: dumps upcoming birthday matches for the
 * app's own member registry without sending mail.
 *
 * Usage: occ birthdayreminder:debug-upcoming [--offsets=30,14,2,1,0]
 */
class DebugUpcoming extends Command {
    public function __construct(
        private MemberMapper $memberMapper,
        private ReminderCalculator $calculator,
        private ClockService $clockService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('birthdayreminder:debug-upcoming')
            ->setDescription('Print upcoming birthday matches for the active member registry (no mail sent)')
            ->addOption('offsets', null, InputOption::VALUE_REQUIRED, 'Comma-separated days-before values', '30,14,2,1,0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $members = array_map(
            static fn (MemberEntity $entity) => new Member(
                uid: (string)$entity->getId(),
                displayName: $entity->getDisplayName(),
                email: $entity->getEmail(),
                month: $entity->getBirthMonth(),
                day: $entity->getBirthDay(),
                year: $entity->getBirthYear(),
                firstName: $entity->getFirstName(),
            ),
            $this->memberMapper->findAllActive()
        );
        $output->writeln(sprintf('%d aktive(s) Mitglied(er) in der Registry.', count($members)));

        $offsets = array_map('intval', explode(',', (string)$input->getOption('offsets')));
        $today = $this->clockService->today();
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
}
