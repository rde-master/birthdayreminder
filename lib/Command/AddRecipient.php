<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Command;

use OCA\BirthdayReminder\Db\OffsetMapper;
use OCA\BirthdayReminder\Db\Recipient;
use OCA\BirthdayReminder\Db\RecipientMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interim management command for M2/M3 (no admin UI yet):
 * occ birthdayreminder:add-recipient user daniel --offsets=30,14,2,1,0 [--only-milestones]
 */
class AddRecipient extends Command {
    public function __construct(
        private RecipientMapper $recipientMapper,
        private OffsetMapper $offsetMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('birthdayreminder:add-recipient')
            ->setDescription('Add or update a recipient and their reminder offsets')
            ->addArgument('type', InputArgument::REQUIRED, 'user|group|email')
            ->addArgument('value', InputArgument::REQUIRED, 'NC user id / group id / e-mail address')
            ->addOption('offsets', null, InputOption::VALUE_REQUIRED, 'Comma-separated days-before values, replaces existing offsets', '30,14,2,1,0')
            ->addOption('only-milestones', null, InputOption::VALUE_NONE, 'Only notify for milestone-age birthdays');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $type = $input->getArgument('type');
        if (!in_array($type, [Recipient::TYPE_USER, Recipient::TYPE_GROUP, Recipient::TYPE_EMAIL], true)) {
            $output->writeln('<error>type must be one of: user, group, email</error>');
            return 1;
        }

        $value = $input->getArgument('value');
        $recipient = $this->recipientMapper->findOrCreate($type, $value);
        $recipient->setOnlyMilestones((bool)$input->getOption('only-milestones'));
        $this->recipientMapper->update($recipient);

        $this->offsetMapper->deleteByRecipientId($recipient->getId());
        $offsets = array_map('intval', explode(',', (string)$input->getOption('offsets')));
        foreach ($offsets as $daysBefore) {
            $this->offsetMapper->add($recipient->getId(), $daysBefore);
        }

        $output->writeln(sprintf(
            'Recipient %s:%s -> offsets [%s]%s',
            $type,
            $value,
            implode(', ', $offsets),
            $recipient->getOnlyMilestones() ? ' (nur runde Geburtstage)' : ''
        ));

        return 0;
    }
}
