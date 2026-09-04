<?php

namespace ControleOnline\WebsocketServer\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\WebsocketTestEventService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class WebsocketTestEventServiceTest extends TestCase
{
    public function testDispatchBroadcastsStoreOpenedEventOncePerUniqueDevice(): void
    {
        $company = $this->createStub(People::class);
        $company->method('getId')->willReturn(88);
        $company->method('getName')->willReturn('Loja Central');
        $company->method('getAlias')->willReturn('Loja Central');

        $firstDevice = $this->createStub(Device::class);
        $firstDevice->method('getId')->willReturn(11);
        $firstDevice->method('getDevice')->willReturn('device-11');
        $firstDevice->method('getAlias')->willReturn('Device 11');

        $secondDevice = $this->createStub(Device::class);
        $secondDevice->method('getId')->willReturn(12);
        $secondDevice->method('getDevice')->willReturn('device-12');
        $secondDevice->method('getAlias')->willReturn('Device 12');

        $firstConfig = $this->createStub(DeviceConfig::class);
        $firstConfig->method('getDevice')->willReturn($firstDevice);

        $duplicateConfig = $this->createStub(DeviceConfig::class);
        $duplicateConfig->method('getDevice')->willReturn($firstDevice);

        $secondConfig = $this->createStub(DeviceConfig::class);
        $secondConfig->method('getDevice')->willReturn($secondDevice);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $deviceService = $this->createMock(DeviceService::class);
        $websocketClient = $this->createMock(WebsocketClient::class);
        $sentMessages = [];

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $company])
            ->willReturn([$firstConfig, $duplicateConfig, $secondConfig]);

        $deviceService
            ->expects(self::once())
            ->method('resolvePeopleReference')
            ->with(88)
            ->willReturn($company);

        $websocketClient
            ->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(function (Device $device, string $message) use (&$sentMessages): Integration {
                $sentMessages[] = [$device, $message];

                return $this->createIntegrationWithId(count($sentMessages) + 100);
            });

        $result = (new WebsocketTestEventService(
            $entityManager,
            $deviceService,
            $websocketClient
        ))->dispatch([
            'company' => 88,
            'event' => 'store.opened',
        ]);

        self::assertCount(2, $sentMessages);
        self::assertSame($firstDevice, $sentMessages[0][0]);
        self::assertSame($secondDevice, $sentMessages[1][0]);
        self::assertSame(
            json_encode($result['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $sentMessages[0][1]
        );
        self::assertSame('store.opened', $result['event']);
        self::assertSame('open', $result['payload'][0]['realStatus']);
        self::assertSame('Aberta', $result['payload'][0]['notificationStatusLabel']);
        self::assertSame([101, 102], $result['integrationIds']);
    }

    public function testDispatchBuildsOrderCreatedPayloadForSpecificDevice(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $deviceService = $this->createMock(DeviceService::class);
        $websocketClient = $this->createMock(WebsocketClient::class);
        $company = $this->createStub(People::class);
        $company->method('getId')->willReturn(88);
        $company->method('getName')->willReturn('Loja Central');
        $company->method('getAlias')->willReturn('Loja Central');

        $device = $this->createStub(Device::class);
        $device->method('getId')->willReturn(44);
        $device->method('getDevice')->willReturn('runner-device');
        $device->method('getAlias')->willReturn('Runner');
        $capturedMessage = null;

        $deviceService
            ->expects(self::once())
            ->method('resolvePeopleReference')
            ->with(88)
            ->willReturn($company);

        $deviceService
            ->expects(self::once())
            ->method('discoveryDevice')
            ->with('runner-device')
            ->willReturn($device);

        $websocketClient
            ->expects(self::once())
            ->method('push')
            ->willReturnCallback(function (Device $sentDevice, string $message) use (&$capturedMessage): Integration {
                $capturedMessage = [$sentDevice, $message];

                return $this->createIntegrationWithId(901);
            });

        $result = (new WebsocketTestEventService(
            $entityManager,
            $deviceService,
            $websocketClient
        ))->dispatch([
            'company' => 88,
            'destination' => 'runner-device',
            'event' => 'order.created',
            'notificationHeader' => 'Pedido fake',
            'notificationSubheader' => 'Teste de websocket',
        ]);

        self::assertNotNull($capturedMessage);
        self::assertSame($device, $capturedMessage[0]);
        self::assertSame('order.created', $result['event']);
        self::assertSame(88, $result['payload'][0]['company']);
        self::assertSame(88, $result['payload'][0]['provider']);
        self::assertSame('open', $result['payload'][0]['realStatus']);
        self::assertSame(true, $result['payload'][0]['alertSound']);
        self::assertSame('Pedido fake', $result['payload'][0]['notificationHeader']);
        self::assertSame(901, $result['integrationIds'][0]);
    }

    private function createIntegrationWithId(int $id): Integration
    {
        $integration = new Integration();
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($integration, $id);

        return $integration;
    }
}
