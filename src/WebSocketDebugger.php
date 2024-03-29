<?php

/** @noinspection DuplicatedCode */

declare(strict_types=1);
/**
 * This file is part of Cloud-Admin project.
 *
 * @link     https://www.cloud-admin.jayjay.cn
 * @document https://wiki.cloud-admin.jayjay.cn
 * @license  https://github.com/swow-cloud/swow-admin/blob/master/LICENSE
 */

namespace SwowCloud\SDB;

use Cron\CronExpression;
use Error;
use Exception;
use Hyperf\Codec\Json;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Crontab\CrontabManager;
use Hyperf\Redis\Pool\PoolFactory;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use RuntimeException;
use Swow\Channel;
use Swow\Coroutine;
use Swow\CoroutineException;
use Swow\Debug\Debugger\Debugger;
use Swow\Debug\Debugger\DebuggerException;
use Swow\Errno;
use Swow\Http\Protocol\ProtocolException;
use Swow\Http\Status;
use Swow\Psr7\Message\UpgradeType;
use Swow\Psr7\Psr7;
use Swow\Psr7\Server\Server;
use Swow\Socket;
use Swow\SocketException;
use Swow\WebSocket\Opcode;
use Swow\WebSocket\WebSocket;
use SwowCloud\SDB\Business\Route;
use SwowCloud\SDB\Config\ServerConfig;
use SwowCloud\SDB\Config\SslConfig;
use Throwable;
use WeakMap;

use function array_filter;
use function array_shift;
use function base64_decode;
use function bin2hex;
use function CloudAdmin\Utils\di;
use function CloudAdmin\Utils\logger;
use function count;
use function ctype_print;
use function explode;
use function Hyperf\Support\env;
use function Hyperf\Support\make;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;
use function sleep;
use function sprintf;
use function strtolower;
use function substr;
use function Swow\Debug\var_dump_return;
use function trim;
use function usleep;

/**
 * Class WebSocketDebugger.
 *
 * This class represents a WebSocket debugger that extends the base Debugger class.
 * It allows debugging of coroutines using a WebSocket connection.
 */
final class WebSocketDebugger extends Debugger
{
    protected ?Server $socket = null;

    protected static ServerConfig $serverConfig;

    protected static ?SslConfig $sslConfig = null;

    final public function __construct()
    {
        parent::__construct();
    }

    final public static function createWithWebSocket(
        string $keyword = 'sdb',
        ServerConfig $serverConfig = null,
        SslConfig $sslConfig = null,
    ): static {
        if ($serverConfig === null) {
            throw new RuntimeException('Debugger Server Not Setting...');
        }

        self::$serverConfig = $serverConfig;
        self::$sslConfig = $sslConfig;
        return self::getInstance()->run($keyword);
    }

    public function start(): void
    {
        $this->socket = new Server(Socket::TYPE_TCP);
        $this
            ->socket
            ->bind(self::$serverConfig->getHost(), self::$serverConfig->getPort())
            ->listen(self::$serverConfig->getBackLog());
        $options = null;

        if (self::$sslConfig !== null && self::$sslConfig->getSsl()) {
            $options = self::$sslConfig->toArray();
            unset($options['ssl']);
        }

        while (true) {
            try {
                $connection = null;
                $connection = $this->socket->acceptConnection();

                if ($options !== null) {
                    $connection->enableCrypto($options);
                }

                Coroutine::run(function () use ($connection): void {
                    try {
                        while (true) {
                            $request = null;

                            try {
                                $request = $connection->recvHttpRequest();

                                if (env('ENABLE_BASIC')) {
                                    if ($request->getHeader('authorization')) {
                                        $auth = substr($request->getHeader('authorization')[0], 6);
                                        $decoded = base64_decode($auth);
                                        [$username, $password] = explode(':', $decoded);
                                        if ($username !== env('BASIC_USERNAME') && $password !== env('BASIC_PASSWORD')) {
                                            $connection->error(Status::UNAUTHORIZED);
                                            break;
                                        }
                                    } else {
                                        $connection->error(Status::UNAUTHORIZED);
                                        break;
                                    }
                                }

                                switch ($request->getUri()->getPath()) {
                                    case '/':
                                        $upgradeType = Psr7::detectUpgradeType($request);

                                        if (($upgradeType & UpgradeType::UPGRADE_TYPE_WEBSOCKET) === 0) {
                                            throw new ProtocolException(
                                                Status::BAD_REQUEST,
                                                'Unsupported Upgrade Type',
                                            );
                                        }

                                        $connection->upgradeToWebSocket($request);
                                        $request = null;

                                        while (true) {
                                            $frame = $connection->recvWebSocketFrame();
                                            $opcode = $frame->getOpcode();

                                            switch ($opcode) {
                                                case Opcode::PING:
                                                    $connection->send(
                                                        WebSocket::PONG_FRAME,
                                                    );
                                                    break;
                                                case Opcode::PONG:
                                                    break;
                                                case Opcode::CLOSE:
                                                    break 4;
                                                case Opcode::TEXT:
                                                    $in = $frame->getPayloadData()->getContents();

                                                    if ($in === "\n") {
                                                        $in = $this->getLastCommand();
                                                    }

                                                    $this->setLastCommand($in);

                                                    _next:

                                                    try {
                                                        $lines = array_filter(explode("\n", $in));

                                                        foreach ($lines as $line) {
                                                            $arguments = explode(' ', $line);

                                                            foreach ($arguments as &$argument) {
                                                                $argument = trim($argument);
                                                            }

                                                            unset($argument);
                                                            $arguments = array_filter($arguments, static fn (string $value) => $value !== '');
                                                            $command = array_shift($arguments);

                                                            switch ($command) {
                                                                case 'ps':
                                                                    $this->showCoroutines(Coroutine::getAll());
                                                                    break;
                                                                case 'attach':
                                                                case 'co':
                                                                case 'coroutine':
                                                                    $id = $arguments[0] ?? 'unknown';

                                                                    if (! is_numeric($id)) {
                                                                        throw new DebuggerException('Argument[1]: Coroutine id must be numeric');
                                                                    }

                                                                    $coroutine = Coroutine::get((int) $id);

                                                                    if (! $coroutine) {
                                                                        throw new DebuggerException("Coroutine#{$id} Not found");
                                                                    }

                                                                    if ($command === 'attach') {
                                                                        $this->checkBreakPointHandler();

                                                                        if ($coroutine === Coroutine::getCurrent()) {
                                                                            throw new DebuggerException('Attach debugger is not allowed');
                                                                        }

                                                                        self::getDebugContextOfCoroutine($coroutine)->stop = true;
                                                                    }

                                                                    $this->setCurrentCoroutine($coroutine);
                                                                    $in = 'bt';
                                                                    goto _next;

                                                                case 'bt':
                                                                case 'backtrace':
                                                                    $this->showCoroutine($this->getCurrentCoroutine(), false)->showSourceFileContentByTrace($this->getCurrentCoroutineTrace(), 0, true);
                                                                    break;
                                                                case 'f':
                                                                case 'frame':
                                                                    $frameIndex = $arguments[0] ?? null;

                                                                    if (! is_numeric($frameIndex)) {
                                                                        throw new DebuggerException('Frame index must be numeric');
                                                                    }

                                                                    $frameIndex = (int) $frameIndex;

                                                                    if ($this->getCurrentFrameIndex() !== $frameIndex) {
                                                                        $this->out("Switch to frame {$frameIndex}");
                                                                    }

                                                                    $this->setCurrentFrameIndex($frameIndex);
                                                                    $trace = $this->getCurrentCoroutineTrace();
                                                                    $frameIndex = $this->getCurrentFrameIndex();
                                                                    $this->showTrace($trace, $frameIndex, false)->showSourceFileContentByTrace($trace, $frameIndex, true);
                                                                    break;
                                                                case 'b':
                                                                case 'breakpoint':
                                                                    $breakPoint = $arguments[0] ?? '';

                                                                    if ($breakPoint === '') {
                                                                        throw new DebuggerException('Invalid break point');
                                                                    }

                                                                    $coroutine = $this->getCurrentCoroutine();

                                                                    if ($coroutine === Coroutine::getCurrent()) {
                                                                        $this->out("Added global break-point <{$breakPoint}>")->addBreakPoint($breakPoint);
                                                                    }

                                                                    break;
                                                                case 'n':
                                                                case 'next':
                                                                case 's':
                                                                case 'step':
                                                                case 'step_in':
                                                                case 'c':
                                                                case 'continue':
                                                                    $coroutine = $this->getCurrentCoroutine();
                                                                    $context = self::getDebugContextOfCoroutine($coroutine);

                                                                    if (! $context->stopped) {
                                                                        if ($context->stop) {
                                                                            $this->waitStoppedCoroutine($coroutine);
                                                                        } else {
                                                                            throw new DebuggerException('Not in debugging');
                                                                        }
                                                                    }

                                                                    switch ($command) {
                                                                        case 'n':
                                                                        case 'next':
                                                                        case 's':
                                                                        case 'step_in':
                                                                            if ($command === 'n' || $command === 'next') {
                                                                                $this->lastTraceDepth = $coroutine->getTraceDepth() - self::getCoroutineTraceDiffLevel($coroutine, 'nextCommand');
                                                                            }

                                                                            $coroutine->resume();
                                                                            $this->waitStoppedCoroutine($coroutine);
                                                                            $this->lastTraceDepth = PHP_INT_MAX;
                                                                            $in = 'f 0';
                                                                            goto _next;

                                                                        case 'c':
                                                                        case 'continue':
                                                                            self::getDebugContextOfCoroutine($coroutine)->stop = false;
                                                                            $this->out("Coroutine#{$coroutine->getId()} continue to run...");
                                                                            $coroutine->resume();
                                                                            break;
                                                                        default:
                                                                            throw new Error('Never here');
                                                                    }

                                                                    break;
                                                                case 'l':
                                                                case 'list':
                                                                    $lineCount = $arguments[0] ?? null;

                                                                    if ($lineCount === null) {
                                                                        $this->showFollowingSourceFileContent();
                                                                    } elseif (is_numeric($lineCount)) {
                                                                        $this->showFollowingSourceFileContent((int) $lineCount);
                                                                    } else {
                                                                        throw new DebuggerException('Argument[1]: line no must be numeric');
                                                                    }

                                                                    break;
                                                                case 'p':
                                                                case 'print':
                                                                case 'exec':
                                                                    $expression = implode(' ', $arguments);

                                                                    if (! $expression) {
                                                                        throw new DebuggerException('No expression');
                                                                    }

                                                                    if ($command === 'exec') {
                                                                        $transfer = new Channel();
                                                                        Coroutine::run(
                                                                            static function () use ($expression, $transfer): void {
                                                                                $transfer->push(Coroutine::getCurrent()->eval($expression));
                                                                            }
                                                                        );

                                                                        // TODO: support ctrl + c (also support ctrl + c twice confirm on global scope?)
                                                                        $result = var_dump_return($transfer->pop());
                                                                    } else {
                                                                        $coroutine = $this->getCurrentCoroutine();
                                                                        $index = $this->getCurrentFrameIndexExtendedForExecution();
                                                                        $result = var_dump_return(
                                                                            $coroutine->eval($expression, $index),
                                                                        );
                                                                    }

                                                                    $this->out($result, false);
                                                                    break;
                                                                case 'vars':
                                                                    $coroutine = $this->getCurrentCoroutine();
                                                                    $index = $this->getCurrentFrameIndexExtendedForExecution();
                                                                    $result = var_dump_return($coroutine->getDefinedVars($index));
                                                                    $this->out($result, false);
                                                                    break;
                                                                case 'z':
                                                                case 'zombie':
                                                                case 'zombies':
                                                                    $time = $arguments[0] ?? null;

                                                                    if (! is_numeric($time)) {
                                                                        throw new DebuggerException('Argument[1]: Time must be numeric');
                                                                    }

                                                                    $this->out("Scanning zombie coroutines ({$time}s)...");

                                                                    $switchesMap = new WeakMap();

                                                                    foreach (Coroutine::getAll() as $coroutine) {
                                                                        $switchesMap[$coroutine] = $coroutine->getSwitches();
                                                                    }

                                                                    usleep((int) ($time * 1000 * 1000));
                                                                    $zombies = [];

                                                                    foreach ($switchesMap as $coroutine => $switches) {
                                                                        if ($coroutine->getSwitches() === $switches) {
                                                                            $zombies[] = $coroutine;
                                                                        }
                                                                    }

                                                                    $this->out('Following coroutine maybe zombies:')->showCoroutines($zombies);
                                                                    break;
                                                                case 'kill':
                                                                    if (count($arguments) === 0) {
                                                                        throw new DebuggerException('Required coroutine id');
                                                                    }

                                                                    foreach ($arguments as $index => $argument) {
                                                                        if (! is_numeric($argument)) {
                                                                            $this->exception("Argument[{$index}] '{$argument}' is not numeric");
                                                                        }
                                                                    }

                                                                    foreach ($arguments as $argument) {
                                                                        $coroutine = Coroutine::get((int) $argument);

                                                                        if ($coroutine) {
                                                                            $coroutine->kill();
                                                                            $this->out("Coroutine#{$argument} killed");
                                                                        } else {
                                                                            $this->exception("Coroutine#{$argument} not exists");
                                                                        }
                                                                    }

                                                                    break;
                                                                case 'killall':
                                                                    Coroutine::killAll();
                                                                    $this->out('All coroutines has been killed');
                                                                    break;
                                                                case 'clear':
                                                                    $this->clear();
                                                                    break;
                                                                case 'pool':
                                                                    $poolCmd = $arguments[0] ?? 'mysql';

                                                                    if (! is_string($poolCmd)) {
                                                                        throw new DebuggerException('Argument[1]: Coroutine id must be string,like: pool redis:default,pool mysql:mysql1');
                                                                    }

                                                                    [$pool, $name] = explode(':', $poolCmd, 2) + [
                                                                        'mysql',
                                                                        'default',
                                                                    ];
                                                                    $factory = match (strtolower($pool)) {
                                                                        'mysql' => \Hyperf\DbConnection\Pool\PoolFactory::class,
                                                                        default => PoolFactory::class,
                                                                    };
                                                                    $info = null;
                                                                    $pools = di()->get($factory)->getPool($name);
                                                                    $info[] = [
                                                                        'pool' => $pool,
                                                                        'poolName' => $name,
                                                                        'currentConnections' => $pools->getCurrentConnections(),
                                                                        'connectionsInChannel' => $pools->getConnectionsInChannel(),
                                                                    ];
                                                                    $this->out(Json::encode($info));
                                                                    break;
                                                                case 'config':
                                                                    $config = di()->get(ConfigInterface::class);
                                                                    $reflection = new ReflectionClass($config);
                                                                    $property = $reflection->getProperty('configs');
                                                                    $this->out(
                                                                        Json::encode($property->getValue($config),JSON_PRETTY_PRINT),
                                                                    );
                                                                    break;
                                                                case 'route':
                                                                    $route = make(Route::class);
                                                                    $this->out(Json::encode($route->getRoute()));
                                                                    break;
                                                                case 'crontab':
                                                                    $crontabs = di()->get(CrontabManager::class)->getCrontabs();

                                                                    $data = [];
                                                                    foreach ($crontabs as $crontab) {
                                                                        $cronExpression = new CronExpression($crontab->getRule());
                                                                        $data[] = [
                                                                            'name' => $crontab->getName(),
                                                                            'rule' => $crontab->getRule(),
                                                                            'nextRunTime' => $cronExpression->getNextRunDate()->format('Y-m-d H:i:s'),
                                                                            'callback' => $crontab->getCallback(),
                                                                            'enable' => $crontab->isEnable(),
                                                                            'memo' => $crontab->getMemo(),
                                                                            'options' => $crontab->getOptions(),
                                                                            'environments' => $crontab->getEnvironments(),
                                                                            'timezone' => $crontab->getTimezone(),
                                                                        ];
                                                                    }
                                                                    $this->out(Json::encode($data));
                                                                    break;
                                                                case 'ping':
                                                                    $this->out('pong');
                                                                    break;
                                                                default:
                                                                    if (! ctype_print($command)) {
                                                                        $command = bin2hex($command);
                                                                    }

                                                                    throw new DebuggerException(
                                                                        "Unknown command '{$command}'",
                                                                    );
                                                            }
                                                        }
                                                    } catch (DebuggerException $exception) {
                                                        $this->out($exception->getMessage());
                                                    } catch (Throwable $throwable) {
                                                        $this->out((string) $throwable);
                                                    }
                                            }
                                        }

                                        // no break
                                    default:
                                        $connection->error(Status::NOT_FOUND);
                                }
                            } catch (ProtocolException $exception) {
                                $connection->error($exception->getCode(), $exception->getMessage(), close: true);
                                break;
                            }

                            if (! $connection->shouldKeepAlive()) {
                                break;
                            }
                        }
                    } catch (Exception) {
                        // you can log error here
                    } finally {
                        $connection->close();
                    }
                });
            } catch (CoroutineException|SocketException|Throwable $exception) {
                if (in_array(
                    $exception->getCode(),
                    [Errno::EMFILE, Errno::ENFILE, Errno::ENOMEM],
                    true,
                )) {
                    sleep(1);
                } else {
                    break;
                }
            }
        }
    }

    public function run(string $keyword = ''): static
    {
        if (self::isAlone()) {
            $this->daemon = false;
            $this->logo()->out('[Info]    Program is running...');
            $this->out('[Info]    Press Ctrl+C to stop the server...');
        }

        return $this;
    }

    public function out(string $string = '', bool $newline = true): static
    {
        if ($this->socket === null) {
            return parent::out($string);
        }

        $this->socket->broadcastWebSocketFrame(Psr7::createWebSocketTextFrame(payloadData: $string));

        return $this;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function detectActiveConnections(): void
    {
        while (true) {
            foreach ($this->socket->getConnections() as $connection) {
                try {
                    $connection->checkLiveness();
                } catch (SocketException $exception) {
                    logger()->debug(
                        sprintf(
                            'Client fd:[%s] is closed. Message:[%s]',
                            $connection->getFd(),
                            $exception->getMessage(),
                        ),
                    );
                }
            }

            sleep(20);
        }
    }
}
