<?php

// Usage: php spike.php <vendor/autoload.php> <driver: pdo_mysql> <port>
declare(strict_types=1);

namespace Spike;

require $argv[1];

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

#[ORM\Entity] #[ORM\Table(name: 'spike_group')]
class Group { #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column] public ?int $id = null; #[ORM\Column] public string $code = 'g'; }

#[ORM\Entity] #[ORM\Table(name: 'spike_customer')]
class Customer {
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column] public ?int $id = null;
    #[ORM\Column(unique: true)] public string $email = '';
    #[ORM\ManyToOne(targetEntity: Group::class, cascade: ['persist'])] public ?Group $group = null;
}

#[ORM\Entity] #[ORM\Table(name: 'spike_order')]
class Order { #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column] public ?int $id = null; #[ORM\Column] public string $state = 'cart'; }

final class Ref { public function __construct(public object $entity) {} }

final class DeferredPayload implements \JsonSerializable {
    public function __construct(private array $data) {}
    public function jsonSerialize(): array { return self::resolve($this->data); }
    private static function resolve(array $data): array {
        foreach ($data as $k => $v) {
            if ($v instanceof Ref) { $data[$k] = $v->entity->id; }
            elseif (is_array($v)) { $data[$k] = self::resolve($v); }
        }
        return $data;
    }
}

#[ORM\Entity] #[ORM\Table(name: 'spike_outbox')]
class Outbox {
    #[ORM\Id, ORM\Column(length: 36)] public string $id;
    #[ORM\Column(type: 'json')] public mixed $payload;
    public function __construct(public string $type0, array|DeferredPayload $payload) { $this->id = bin2hex(random_bytes(8)); $this->payload = $payload; }
    #[ORM\Column] public string $type = '';
}

#[ORM\Entity] #[ORM\Table(name: 'spike_delivery')]
class Delivery {
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Outbox::class)] #[ORM\JoinColumn(nullable: false)] public Outbox $event;
    public function __construct(Outbox $e) { $this->event = $e; }
}

final class Capture implements EventSubscriber {
    public function getSubscribedEvents(): array { return [Events::onFlush]; }
    public function onFlush(OnFlushEventArgs $args): void {
        $em = method_exists($args, 'getObjectManager') ? $args->getObjectManager() : $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        $events = [];
        foreach ($uow->getScheduledEntityInsertions() as $e) {
            if ($e instanceof Customer) { $events[] = ['customer.created', ['id' => new Ref($e), 'email' => $e->email, 'group' => $e->group ? ['id' => new Ref($e->group)] : null]]; }
        }
        foreach ($uow->getScheduledEntityUpdates() as $e) {
            if ($e instanceof Order && ($uow->getEntityChangeSet($e)['state'] ?? null) === ['cart', 'new']) { $events[] = ['order.placed', ['id' => new Ref($e)]]; }
        }
        foreach ($events as [$type, $data]) {
            $o = new Outbox($type, new DeferredPayload($data)); $o->type = $type;
            $em->persist($o); $uow->computeChangeSet($em->getClassMetadata(Outbox::class), $o);
            $d = new Delivery($o);
            $em->persist($d); $uow->computeChangeSet($em->getClassMetadata(Delivery::class), $d);
        }
    }
}

$pg = 'pdo_pgsql' === $argv[2];
$params = ['driver' => $argv[2], 'host' => '127.0.0.1', 'port' => (int) $argv[3], 'user' => $pg ? 'postgres' : 'root', 'password' => $pg ? 'postgres' : '', 'dbname' => 'spike'];
$admin = DriverManager::getConnection($pg ? ['dbname' => 'postgres'] + $params : array_diff_key($params, ['dbname' => 1]));
$admin->executeStatement('DROP DATABASE IF EXISTS spike'); $admin->executeStatement('CREATE DATABASE spike');

$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
if (method_exists($config, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) { $config->enableNativeLazyObjects(true); }
$newEm = function () use ($params, $config): EntityManager {
    $em = new EntityManager(DriverManager::getConnection($params), $config);
    $em->getEventManager()->addEventSubscriber(new Capture());
    return $em;
};
$em = $newEm();
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
$probe = DriverManager::getConnection($params); // independent connection: sees committed rows only
$count = fn (string $t) => (int) $probe->fetchOne("SELECT COUNT(*) FROM $t");
$ok = function (string $label, bool $cond) { echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$cond) { $GLOBALS['failed'] = true; } };

printf("ORM %s, DBAL platform %s, server %s\n", \Composer\InstalledVersions::getPrettyVersion('doctrine/orm'), (new \ReflectionClass($em->getConnection()->getDatabasePlatform()))->getShortName(), $probe->fetchOne('SELECT VERSION()'));

// 1. update captured, committed together
$order = new Order(); $em->persist($order); $em->flush();
$order->state = 'new'; $em->flush();
$ok('order.placed committed with the order', 1 === $count('spike_outbox') && 1 === $count('spike_delivery'));
$payload = json_decode((string) $probe->fetchOne("SELECT payload FROM spike_outbox WHERE type = 'order.placed'"), true);
$ok('order.placed payload id = ' . json_encode($payload), $payload['id'] === $order->id);

// 2. insertion: ids resolved lazily at insert time
$c = new Customer(); $c->email = 'a@example.com'; $c->group = new Group(); $em->persist($c); $em->flush();
$payload = json_decode((string) $probe->fetchOne("SELECT payload FROM spike_outbox WHERE type = 'customer.created'"), true);
$ok('customer.created payload ids = ' . json_encode($payload), $payload['id'] === $c->id && $payload['group']['id'] === $c->group->id && null !== $c->id);

// 3. explicit rollback around the flush
$em->getConnection()->beginTransaction();
$c2 = new Customer(); $c2->email = 'b@example.com'; $em->persist($c2); $em->flush();
$ok('row visible inside the transaction', 3 === (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM spike_outbox'));
$em->getConnection()->rollBack();
$ok('nothing committed after rollback', 2 === $count('spike_outbox') && 2 === $count('spike_delivery') && 1 === $count('spike_customer'));

// 4. flush failing after the event insert (duplicate email): nothing committed
$em = $newEm();
$order2 = new Order(); $em->persist($order2); $em->flush();
$order2->state = 'new';
$dup = new Customer(); $dup->email = 'a@example.com'; $em->persist($dup);
try { $em->flush(); $ok('flush should fail', false); } catch (\Throwable $e) { echo '  flush failed as expected: ' . (new \ReflectionClass($e))->getShortName() . "\n"; }
$ok('no event committed when the flush fails', 2 === $count('spike_outbox') && 1 === $count('spike_customer') && 'cart' === $probe->fetchOne('SELECT state FROM spike_order WHERE id = ?', [$order2->id]));

// 5. SKIP LOCKED claim with two connections
$a = DriverManager::getConnection($params); $b = DriverManager::getConnection($params);
$a->beginTransaction(); $b->beginTransaction();
$claim = fn ($conn) => $conn->createQueryBuilder()->select('id')->from('spike_delivery')->orderBy('id')->setMaxResults(1)->forUpdate(ConflictResolutionMode::SKIP_LOCKED);
echo '  SQL: ' . $claim($a)->getSQL() . "\n";
$first = $claim($a)->executeQuery()->fetchOne();
$second = $claim($b)->executeQuery()->fetchOne();
$ok("SKIP LOCKED: worker A got $first, worker B got $second", false !== $first && false !== $second && $first !== $second);
$a->commit(); $b->commit();

exit(isset($GLOBALS['failed']) ? 1 : 0);
