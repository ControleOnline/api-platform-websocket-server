<?php

/*
 * Contract imported from AGENTS.md
 * ## Escopo
 * - Modulo de infraestrutura websocket da API.
 * - Cobre servidor, clientes, controller e utilitarios de comunicacao em tempo real.
 *
 * ## Quando usar
 * - Prompts sobre WebSocket, comandos do servidor realtime, envio de mensagem para devices e infraestrutura de socket.
 *
 * ## Limites
 * - Nao mover regra de negocio de pedido, pagamento ou fila para este modulo.
 * - `websocket-server` deve transportar eventos e comandos, nao decidir a regra principal.
 */


namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;

class WebsocketTestEventService
{
    private const ALLOWED_EVENTS = [
        'order.created',
        'store.opened',
        'store.closed',
    ];

    private const DEFAULT_SOURCE = 'backend-test';

    public function __construct(
        private EntityManagerInterface $manager,
        private DeviceService $deviceService,
        private WebsocketClient $websocketClient,
    ) {
    }

    public function dispatch(array $input): array
    {
        $event = $this->normalizeEventName(
            $this->firstText($input, ['event', 'type']) ?: 'order.created'
        );

        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Evento nao suportado: %s',
                $event
            ));
        }

        $company = $this->resolveCompany($input);
        if (!$company instanceof People) {
            throw new \InvalidArgumentException(
                'Informe company, provider ou people para enviar o evento de teste.'
            );
        }

        $devices = $this->resolveTargetDevices($input, $company);
        if ($devices === []) {
            throw new \InvalidArgumentException(
                'Nenhum device encontrado para enviar o evento de teste.'
            );
        }

        $payload = [$this->buildEventPayload($event, $input, $company)];
        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($jsonPayload === false) {
            throw new \RuntimeException(
                'Nao foi possivel serializar o payload do websocket de teste.'
            );
        }

        $integrations = [];
        foreach ($devices as $device) {
            $integrations[] = $this->websocketClient->push($device, $jsonPayload);
        }

        return [
            'event' => $event,
            'payload' => $payload,
            'jsonPayload' => $jsonPayload,
            'targets' => array_map(
                static fn (Device $device): array => [
                    'id' => $device->getId(),
                    'device' => $device->getDevice(),
                    'alias' => $device->getAlias(),
                ],
                $devices
            ),
            'integrationIds' => array_map(
                static fn (Integration $integration): ?int => $integration->getId(),
                $integrations
            ),
        ];
    }

    private function buildEventPayload(string $event, array $input, People $company): array
    {
        $companyId = $company->getId();
        $companyLabel = $this->resolveCompanyLabel($company, $input);
        $source = $this->normalizeText(
            $this->firstValue($input, ['source'])
        );
        $source = $source !== '' ? $source : self::DEFAULT_SOURCE;

        $payload = [
            'store' => 'orders',
            'event' => $event,
            'company' => $companyId,
            'provider' => $companyId,
            'providerName' => $companyLabel,
            'source' => $source,
            'sentAt' => date(DATE_ATOM),
        ];

        if ($event === 'order.created') {
            $orderId = $this->normalizeOptionalNumericId(
                $this->firstValue($input, ['order', 'orderId', 'id'])
            );

            if ($orderId !== null) {
                $payload['order'] = $orderId;
            }

            $payload['status'] = 'open';
            $payload['realStatus'] = 'open';
            $payload['alertSound'] = $this->normalizeTruthy(
                $this->firstValue($input, ['alertSound']),
                true
            );
            $payload['message'] = $this->normalizeText(
                $this->firstValue($input, ['message'])
            ) ?: sprintf(
                'Pedido de teste para %s',
                $companyLabel !== '' ? $companyLabel : 'a empresa'
            );
            $payload['notificationHeader'] = $this->normalizeText(
                $this->firstValue($input, ['notificationHeader'])
            ) ?: ($companyLabel !== ''
                ? sprintf('Novo pedido em %s', $companyLabel)
                : 'Novo pedido');
            $payload['notificationSubheader'] = $this->normalizeText(
                $this->firstValue($input, ['notificationSubheader'])
            ) ?: 'Disparo fake enviado pelo backend.';
            $payload['notificationBody'] = $this->normalizeText(
                $this->firstValue($input, ['notificationBody'])
            ) ?: 'Usado para validar o websocket e o runtime em background.';
            $payload['notificationStatusLabel'] = $this->normalizeText(
                $this->firstValue($input, ['notificationStatusLabel'])
            ) ?: 'Fila';

            return $payload;
        }

        $isOpenEvent = $event === 'store.opened';
        $payload['status'] = $isOpenEvent ? 'open' : 'closed';
        $payload['realStatus'] = $isOpenEvent ? 'open' : 'closed';
        $payload['alertSound'] = $this->normalizeTruthy(
            $this->firstValue($input, ['alertSound']),
            true
        );
        $payload['message'] = $this->normalizeText(
            $this->firstValue($input, ['message'])
        ) ?: ($companyLabel !== ''
            ? sprintf(
                'Loja %s %s',
                $companyLabel,
                $isOpenEvent ? 'aberta' : 'fechada'
            )
            : sprintf('Loja %s', $isOpenEvent ? 'aberta' : 'fechada'));
        $payload['notificationHeader'] = $this->normalizeText(
            $this->firstValue($input, ['notificationHeader'])
        ) ?: ($companyLabel !== ''
            ? sprintf('%s foi %s', $companyLabel, $isOpenEvent ? 'aberta' : 'fechada')
            : sprintf('Loja foi %s', $isOpenEvent ? 'aberta' : 'fechada'));
        $payload['notificationSubheader'] = $this->normalizeText(
            $this->firstValue($input, ['notificationSubheader'])
        ) ?: ($isOpenEvent
            ? 'A loja voltou a ficar online.'
            : 'A loja ficou indisponivel.');
        $payload['notificationBody'] = $this->normalizeText(
            $this->firstValue($input, ['notificationBody'])
        ) ?: 'Aviso fake enviado pelo backend.';
        $payload['notificationStatusLabel'] = $this->normalizeText(
            $this->firstValue($input, ['notificationStatusLabel'])
        ) ?: ($isOpenEvent ? 'Aberta' : 'Fechada');

        return $payload;
    }

    private function resolveTargetDevices(array $input, People $company): array
    {
        $destination = $this->normalizeText(
            $this->firstValue($input, ['destination', 'device', 'deviceId'])
        );

        if ($destination !== '') {
            return [$this->deviceService->discoveryDevice($destination)];
        }

        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $company,
        ]);

        $devices = [];
        $seenDeviceIds = [];

        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            $deviceId = $device->getId();
            if (isset($seenDeviceIds[$deviceId])) {
                continue;
            }

            $seenDeviceIds[$deviceId] = true;
            $devices[] = $device;
        }

        return $devices;
    }

    private function resolveCompany(array $input): ?People
    {
        $companyReference = $this->firstValue(
            $input,
            ['company', 'companyId', 'provider', 'providerId', 'people', 'peopleId']
        );

        $companyReference = $this->extractScalarReference($companyReference);
        if ($companyReference === null) {
            return null;
        }

        $company = $this->deviceService->resolvePeopleReference($companyReference);
        if ($company instanceof People) {
            return $company;
        }

        return null;
    }

    private function resolveCompanyLabel(People $company, array $input): string
    {
        $override = $this->normalizeText(
            $this->firstValue($input, ['providerName', 'companyName', 'name'])
        );
        if ($override !== '') {
            return $override;
        }

        $alias = $this->normalizeText($company->getAlias());
        if ($alias !== '') {
            return $alias;
        }

        return $this->normalizeText($company->getName());
    }

    private function normalizeEventName(mixed $value): string
    {
        return strtolower($this->normalizeText($value));
    }

    private function firstValue(array $input, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function firstText(array $input, array $keys): string
    {
        return $this->normalizeText($this->firstValue($input, $keys));
    }

    private function normalizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeOptionalNumericId(mixed $value): ?int
    {
        $scalarValue = $this->extractScalarReference($value);
        if ($scalarValue === null) {
            return null;
        }

        $normalized = (int) preg_replace('/\D+/', '', (string) $scalarValue);

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeTruthy(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower($this->normalizeText($value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function extractScalarReference(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach (['id', '@id', 'device', 'company', 'provider', 'people'] as $key) {
                if (array_key_exists($key, $value) && $value[$key] !== null && $value[$key] !== '') {
                    return $value[$key];
                }
            }

            return null;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getId')) {
                return $value->getId();
            }

            if (method_exists($value, 'getDevice')) {
                return $value->getDevice();
            }
        }

        return $value;
    }
}
