<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Service\AdvisingAppointmentService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdvisingAppointmentController
{
    public function __construct(private AdvisingAppointmentService $service)
    {
    }

    public function staffList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'list');
    }

    public function advisorList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'list');
    }

    public function ownerList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'owner', 'list');
    }

    public function staffSchedule(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'schedule');
    }

    public function advisorSchedule(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'schedule');
    }

    public function staffAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'act');
    }

    public function advisorAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'act');
    }

    public function ownerAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'owner', 'act');
    }

    private function dispatch(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
        string $channel,
        string $method,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $s = new \Domain\Shared\ValueObject\EntityId($student);
        $p = new \Domain\Shared\ValueObject\EntityId($pursuit);
        if ($method === 'list') {
            return new JsonResponse($this->service->listing(
                $s,
                $p,
                $channel,
                $f->boolean('finished'),
                $f->optionalId('after')
            ));
        }
        if ($method === 'schedule') {
            return new JsonResponse($this->service->schedule(
                $s,
                $p,
                $channel,
                $f->id('appointment_id'),
                $f->id('host_person_id'),
                $f->text('start', 16),
                $f->text('end', 16),
                $f->text('timezone', 80),
                $f->bodyText('message')
            ));
        }
        $action = $f->text('action', 12);
        $time = $action === 'reschedule' ? new AppointmentTime(
            $f->text('start', 16),
            $f->text('end', 16),
            $f->text('timezone', 80)
        ) : null;
        return new JsonResponse($this->service->act(
            $s,
            $p,
            $channel,
            $f->id('appointment_id'),
            $f->id('operation_id'),
            $action,
            $f->integer('version'),
            $time,
            $f->bodyText('message')
        ));
    }
}
