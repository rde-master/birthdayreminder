<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Service\CsvImportService;
use OCA\BirthdayReminder\Service\CsvParser;
use OCA\BirthdayReminder\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MembersApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MemberMapper $memberMapper,
        private CsvImportService $csvImportService,
        private CsvParser $csvParser,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getMembers(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Member $m) => $this->serializeMember($m),
            $this->memberMapper->findAll()
        ));
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveMember(
        ?int $id,
        string $firstName,
        string $lastName,
        int $birthDay,
        int $birthMonth,
        ?int $birthYear,
        ?string $email,
        bool $disabled,
        ?string $remark,
    ): JSONResponse {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '' || $lastName === '') {
            return new JSONResponse(['error' => 'Vorname und Nachname sind erforderlich'], 400);
        }
        if (!checkdate($birthMonth, $birthDay, $birthYear ?? 2000)) {
            return new JSONResponse(['error' => 'Ungültiges Geburtsdatum'], 400);
        }

        if ($id !== null) {
            try {
                $member = $this->memberMapper->find($id);
            } catch (DoesNotExistException) {
                return new JSONResponse(['error' => 'not found'], 404);
            }
        } else {
            $member = new Member();
            $member->setCreatedAt(time());
        }

        $email = $email !== null ? trim($email) : null;

        $member->setFirstName($firstName);
        $member->setLastName($lastName);
        $member->setBirthDay($birthDay);
        $member->setBirthMonth($birthMonth);
        $member->setBirthYear($birthYear);
        $member->setEmail($email !== '' ? $email : null);
        $member->setDisabled($disabled);
        $member->setRemark($remark !== null && trim($remark) !== '' ? trim($remark) : null);
        $member->setUpdatedAt(time());

        $saved = $member->getId() === null ? $this->memberMapper->insert($member) : $this->memberMapper->update($member);

        return new JSONResponse($this->serializeMember($saved));
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function deleteMember(int $id): JSONResponse {
        try {
            $member = $this->memberMapper->find($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'not found'], 404);
        }
        $this->memberMapper->delete($member);
        return new JSONResponse(['ok' => true]);
    }

    /**
     * @param array{firstName: string, lastName: string, birthdate: string, email: string} $mapping
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function importMembers(string $csvContent, array $mapping, ?string $delimiter = null): JSONResponse {
        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : $this->csvParser->guessDelimiter($csvContent);
        $result = $this->csvImportService->import($csvContent, $delimiter, $mapping);
        return new JSONResponse($result);
    }

    private function serializeMember(Member $m): array {
        return [
            'id' => $m->getId(),
            'firstName' => $m->getFirstName(),
            'lastName' => $m->getLastName(),
            'birthDay' => $m->getBirthDay(),
            'birthMonth' => $m->getBirthMonth(),
            'birthYear' => $m->getBirthYear(),
            'email' => $m->getEmail(),
            'disabled' => $m->getDisabled(),
            'remark' => $m->getRemark(),
        ];
    }
}
