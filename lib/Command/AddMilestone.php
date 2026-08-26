<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Command;

use OCA\BirthdayReminder\Db\Milestone;
use OCA\BirthdayReminder\Db\MilestoneMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interim management command for M2/M3 (no admin UI yet):
 * occ birthdayreminder:add-milestone 18 "Tankgutschein über 30 EUR"
 */
class AddMilestone extends Command {
    public function __construct(
        private MilestoneMapper $milestoneMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('birthdayreminder:add-milestone')
            ->setDescription('Add or update a milestone age and its gift suggestion')
            ->addArgument('age', InputArgument::REQUIRED, 'Age, e.g. 18')
            ->addArgument('giftText', InputArgument::REQUIRED, 'Gift suggestion text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $age = (int)$input->getArgument('age');
        $giftText = (string)$input->getArgument('giftText');

        $milestone = $this->milestoneMapper->findByAge($age);
        if ($milestone === null) {
            $milestone = new Milestone();
            $milestone->setAge($age);
            $milestone->setGiftText($giftText);
            $this->milestoneMapper->insert($milestone);
        } else {
            $milestone->setGiftText($giftText);
            $this->milestoneMapper->update($milestone);
        }

        $output->writeln(sprintf('Milestone %d -> "%s"', $age, $giftText));
        return 0;
    }
}
