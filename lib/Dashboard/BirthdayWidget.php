<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Dashboard;

use OCA\BirthdayReminder\AppInfo\Application;
use OCA\BirthdayReminder\Service\ReminderService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Shows the next upcoming member birthdays on the Nextcloud Dashboard.
 * Implements IAPIWidgetV2 so the Dashboard app's own generic Vue renderer
 * displays the list - no widget-specific frontend JS needed.
 */
class BirthdayWidget implements IAPIWidgetV2, IIconWidget {
    public function __construct(
        private ReminderService $reminderService,
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getTitle(): string {
        return $this->l10n->t('Geburtstage');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-calendar';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->imagePath('core', 'places/calendar.svg');
    }

    public function getUrl(): ?string {
        return null;
    }

    public function load(): void {
        // Nothing to bootstrap - IAPIWidgetV2 is rendered by the Dashboard
        // app's own generic list widget, no app-specific JS/CSS needed.
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $upcoming = $this->reminderService->getUpcomingBirthdays($limit);

        $items = array_map(function (array $entry): WidgetItem {
            return new WidgetItem(
                $entry['member']->displayName,
                $this->formatSubtitle($entry),
                '',
                $this->urlGenerator->imagePath('core', 'actions/user.svg'),
            );
        }, $upcoming);

        return new WidgetItems($items, $this->l10n->t('Keine anstehenden Geburtstage'));
    }

    /**
     * @param array{daysUntil: int, age: ?int} $entry
     */
    private function formatSubtitle(array $entry): string {
        $days = $entry['daysUntil'];
        if ($days === 0) {
            $when = $this->l10n->t('heute');
        } elseif ($days === 1) {
            $when = $this->l10n->t('morgen');
        } else {
            $when = $this->l10n->t('in %s Tagen', [(string)$days]);
        }

        if ($entry['age'] !== null) {
            return $this->l10n->t('%1$s, wird %2$d', [$when, $entry['age']]);
        }
        return $when;
    }
}
