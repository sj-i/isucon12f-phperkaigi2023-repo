<?php

declare(strict_types=1);

namespace App\IsuConquest;

use App\Application\Settings\SettingsInterface;
use DateTimeImmutable;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface as Logger;
use Redis;
use RuntimeException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Handler
{
    use Common;

    private const DECK_CARD_NUMBER = 3;
    private const PRESENT_COUNT_PER_PAGE = 100;

    private const SQL_DIRECTORY = __DIR__ . '/../../../sql/';

    public function __construct(
        private readonly DatabaseManager     $databaseManager,
        private readonly Logger              $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsInterface   $settings,
        private readonly MasterCache         $masterCache,
        private readonly BanChecker          $banChecker,
        private readonly ViewerIDChecker     $viewerIDChecker,
        private readonly SessionStore        $sessionStore,
    ) {
    }

    /**
     * apiMiddleware
     */
    public function apiMiddleware(Request $request, RequestHandler $handler): Response
    {
        $requestAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $request->getHeader('x-isu-date')[0] ?? '');
        if ($requestAt === false) {
            $requestAt = new DateTimeImmutable();
        }
        $request = $request->withAttribute('requestTime', $requestAt->getTimestamp());

        try {
            $userID = $this->getUserID($request);
        } catch (\Throwable $e) {
            $userID = 0;
        }
        // マスタ確認
        $masterVersion = $this->masterCache->shouldRecache($this->databaseManager->selectDatabase($userID));

        if (!$request->hasHeader('x-master-version') || $masterVersion !== $request->getHeader('x-master-version')[0]) {
            throw new HttpException($request, $this->errInvalidMasterVersion, StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        // check ban
        if ($userID !== 0) {
            try {
                $isBan = $this->checkBan($userID);
            } catch (PDOException $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }
            if ($isBan) {
                throw new HttpUnauthorizedException($request, $this->errUnauthorized);
            }
        }

        // next
        return $handler->handle($request);
    }

    /**
     * checkSessionMiddleware
     */
    public function checkSessionMiddleware(Request $request, RequestHandler $handler): Response
    {
        $sessID = $request->getHeader('x-session')[0] ?? '';
        if ($sessID === '') {
            throw new HttpUnauthorizedException($request, $this->errUnauthorized);
        }

        $sessionUserIDStrs = explode('::', $sessID);
        if (!is_array($sessionUserIDStrs) or count($sessionUserIDStrs) !== 2) {
            throw new HttpUnauthorizedException($request, $this->errUnauthorized);
        }
        $sessionUserID = (int)$sessionUserIDStrs[1];

        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $userSession = $this->sessionStore->getSession($sessID);
        if (is_null($userSession)) {
            throw new HttpUnauthorizedException($request, $this->errUnauthorized);
        }

        if ($userSession->userID !== $userID) {
            throw new HttpForbiddenException($request, $this->errForbidden);
        }

        // next
        return $handler->handle($request);
    }

    /**
     * checkOneTimeToken
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    private function checkOneTimeToken(int $userID, string $token, int $tokenType, int $requestAt): void
    {
        $query = 'SELECT * FROM user_one_time_tokens WHERE token=? AND token_type=? AND deleted_at IS NULL';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $token);
        $stmt->bindValue(2, $tokenType, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException($this->errInvalidToken);
        }
        $tk = UserOneTimeToken::fromDBRow($row);

        if ($tk->expiredAt < $requestAt) {
            $query = 'UPDATE user_one_time_tokens SET deleted_at=? WHERE token=?';
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(2, $token);
            $stmt->execute();
            throw new RuntimeException($this->errInvalidToken);
        }

        // 使ったトークンを失効する
        $query = 'UPDATE user_one_time_tokens SET deleted_at=? WHERE token=?';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
        $stmt->bindValue(2, $token);
        $stmt->execute();
    }

    /**
     * checkViewerID
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    private function checkViewerID(int $userID, string $viewerID): void
    {
        if (!$this->viewerIDChecker->check($userID, $viewerID)) {
            throw new RuntimeException($this->errUserDeviceNotFound);
        }
    }

    /**
     * checkBan
     *
     * @throws PDOException
     */
    private function checkBan(int $userID): bool
    {
        return $this->banChecker->isBan($userID);
    }

    /**
     * loginProcess ログイン処理
     *
     * @return array{User, list<UserLoginBonus>, list<UserPresent>}
     * @throws PDOException
     * @throws RuntimeException
     */
    private function loginProcess(int $userID, int $requestAt): array
    {
        $query = 'SELECT * FROM users WHERE id=?';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $userID, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException($this->errUserNotFound);
        }
        $user = User::fromDBRow($row);

        // ログインボーナス処理
        $loginBonuses = $this->obtainLoginBonus($userID, $requestAt);

        // 全員プレゼント取得
        $allPresents = $this->obtainPresent($userID, $requestAt);

        $stmt = $this->databaseManager->selectDatabase($userID)->prepare('SELECT isu_coin FROM users WHERE id=?');
        $stmt->bindValue(1, $user->id, PDO::PARAM_INT);
        $stmt->execute();
        $isuCoin = $stmt->fetchColumn();
        if ($isuCoin === false) {
            throw new RuntimeException($this->errUserNotFound);
        }
        $user->isuCoin = $isuCoin;

        $user->updatedAt = $requestAt;
        $user->lastActivatedAt = $requestAt;

        $query = 'UPDATE users SET updated_at=?, last_activated_at=? WHERE id=?';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
        $stmt->bindValue(2, $requestAt, PDO::PARAM_INT);
        $stmt->bindValue(3, $userID, PDO::PARAM_INT);

        return [$user, $loginBonuses, $allPresents];
    }

    /**
     * isCompleteTodayLogin ログイン処理が終わっているか
     */
    private function isCompleteTodayLogin(int $lastActivatedAt, int $requestAt): bool
    {
        return date(format: 'Ymd', timestamp: $lastActivatedAt) === date(format: 'Ymd', timestamp: $requestAt);
    }

    /**
     * obtainLoginBonus
     *
     * @return list<UserLoginBonus>
     * @throws PDOException
     * @throws RuntimeException
     */
    private function obtainLoginBonus(int $userID, int $requestAt): array
    {
        // login bonus masterから有効なログインボーナスを取得
        /** @var list<LoginBonusMaster> $loginBonuses */
        $loginBonuses = $this->masterCache->getLoginBonusMaster($requestAt);

        /** @var list<UserLoginBonus> $sendLoginBonuses */
        $sendLoginBonuses = [];

        foreach ($loginBonuses as $bonus) {
            $initBonus = false;
            // ボーナスの進捗取得
            $query = 'SELECT * FROM user_login_bonuses WHERE user_id=? AND login_bonus_id=?';
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->bindValue(2, $bonus->id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row === false) {
                $initBonus = true;
                $ubID = $this->generateID();
                $userBonus = new UserLoginBonus( // ボーナス初期化
                    id: $ubID,
                    userID: $userID,
                    loginBonusID: $bonus->id,
                    lastRewardSequence: 0,
                    loopCount: 1,
                    createdAt: $requestAt,
                    updatedAt: $requestAt,
                );
            } else {
                $userBonus = UserLoginBonus::fromDBRow($row);
            }

            // ボーナス進捗更新
            if ($userBonus->lastRewardSequence < $bonus->columnCount) {
                $userBonus->lastRewardSequence++;
            } else {
                if ($bonus->looped) {
                    $userBonus->loopCount += 1;
                    $userBonus->lastRewardSequence = 1;
                } else {
                    // 上限まで付与完了
                    continue;
                }
            }
            $userBonus->updatedAt = $requestAt;

            // 今回付与するリソース取得
            $rewardItem = $this->masterCache->getLoginBonusRewardMasterByIDAndSequence($bonus->id, $userBonus->lastRewardSequence);
            if (is_null($rewardItem)) {
                throw new RuntimeException($this->errLoginBonusRewardNotFound);
            }

            $this->obtainItem($userID, $rewardItem->itemID, $rewardItem->itemType, $rewardItem->amount, $requestAt);

            // 進捗の保存
            if ($initBonus) {
                $query = 'INSERT INTO user_login_bonuses(id, user_id, login_bonus_id, last_reward_sequence, loop_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
                $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
                $stmt->bindValue(1, $userBonus->id, PDO::PARAM_INT);
                $stmt->bindValue(2, $userBonus->userID, PDO::PARAM_INT);
                $stmt->bindValue(3, $userBonus->loginBonusID, PDO::PARAM_INT);
                $stmt->bindValue(4, $userBonus->lastRewardSequence, PDO::PARAM_INT);
                $stmt->bindValue(5, $userBonus->loopCount, PDO::PARAM_INT);
                $stmt->bindValue(6, $userBonus->createdAt, PDO::PARAM_INT);
                $stmt->bindValue(7, $userBonus->updatedAt, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $query = 'UPDATE user_login_bonuses SET last_reward_sequence=?, loop_count=?, updated_at=? WHERE id=?';
                $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
                $stmt->bindValue(1, $userBonus->lastRewardSequence, PDO::PARAM_INT);
                $stmt->bindValue(2, $userBonus->loopCount, PDO::PARAM_INT);
                $stmt->bindValue(3, $userBonus->updatedAt, PDO::PARAM_INT);
                $stmt->bindValue(4, $userBonus->id, PDO::PARAM_INT);
                $stmt->execute();
            }

            $sendLoginBonuses[] = $userBonus;
        }

        return $sendLoginBonuses;
    }

    /**
     * obtainPresent プレゼント付与処理
     *
     * @return list<UserPresent>
     * @throws PDOException
     * @throws RuntimeException
     */
    private function obtainPresent(int $userID, int $requestAt): array
    {
        /** @var list<PresentAllMaster> $normalPresents */
        $normalPresents = $this->masterCache->getPresentAllMaster($requestAt);

        // 全員プレゼント取得情報更新
        $ids = [];
        foreach ($normalPresents as $np) {
            $ids[] = $np->id;
        }
        $ids_placeholder = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare("SELECT present_all_id FROM user_present_all_received_history WHERE user_id=? AND present_all_id IN ({$ids_placeholder})");
        foreach ([$userID, ...$ids] as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $received_ids = [];
        while ($row = $stmt->fetch()) {
            $received_ids[$row['present_all_id']] = true;
        }
        $histories = [];
        /** @var list<UserPresent> $obtainPresents */
        $obtainPresents = [];
        foreach ($normalPresents as $np) {
            if (isset($received_ids[$np->id])) {
                continue;
            }

            // user present boxに入れる
            // history に入れる
            $pID = $this->generateID();
            $phID = $this->generateID();
            $up = new UserPresent(
                id: $pID,
                userID: $userID,
                sentAt: $requestAt,
                itemType: $np->itemType,
                itemID: $np->itemID,
                amount: $np->amount,
                presentMessage: $np->presentMessage,
                createdAt: $requestAt,
                updatedAt: $requestAt,
            );
            $history = new UserPresentAllReceivedHistory(
                id: $phID,
                userID: $userID,
                presentAllID: $np->id,
                receivedAt: $requestAt,
                createdAt: $requestAt,
                updatedAt: $requestAt,
            );
            $obtainPresents[] = $up;
            $histories[] = $history;
        }
        if (count($obtainPresents)) {
            $placeholder = implode(',', array_fill(0, count($obtainPresents), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
            $query = 'INSERT INTO user_presents(id, user_id, sent_at, item_type, item_id, amount, present_message, created_at, updated_at) VALUES' . $placeholder . ';';
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $position = 1;
            foreach ($obtainPresents as $obtainPresent) {
                $stmt->bindValue($position++, $obtainPresent->id, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->userID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->sentAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->itemType, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->itemID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->amount, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->presentMessage);
                $stmt->bindValue($position++, $obtainPresent->createdAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $obtainPresent->updatedAt, PDO::PARAM_INT);
            }
            $stmt->execute();
        }

        if (count($histories)) {
            $placeholder = implode(',', array_fill(0, count($histories), '(?, ?, ?, ?, ?, ?)'));
            $query = 'INSERT INTO user_present_all_received_history(id, user_id, present_all_id, received_at, created_at, updated_at) VALUES ' . $placeholder  . ';';
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $position = 1;
            foreach ($histories as $history) {
                $stmt->bindValue($position++, $history->id, PDO::PARAM_INT);
                $stmt->bindValue($position++, $history->userID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $history->presentAllID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $history->receivedAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $history->createdAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $history->updatedAt, PDO::PARAM_INT);
            }
            $stmt->execute();
        }

        return $obtainPresents;
    }

    private function obtainCoin(int $userID, int $obtainAmount): void
    {
        $query = 'UPDATE users SET isu_coin=isu_coin+? WHERE id=?';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $obtainAmount, PDO::PARAM_INT);
        $stmt->bindValue(2, $userID, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function obtainCards(int $userID, int $requestAt, array $itemIDs): void
    {
        if (!count($itemIDs)) {
            return;
        }
        $uniqueItemIDs = array_values(array_unique($itemIDs));
        $items = [];
        foreach ($uniqueItemIDs as $itemID) {
            $item = $this->masterCache->getItemMasterById($itemID);
            if (is_null($item)) {
                throw new RuntimeException($this->errItemNotFound);
            }
            $items[$item->id] = $item;
        }
        $cards = [];
        foreach ($itemIDs as $itemID) {
            $item = $items[$itemID];
            $cards[] = new UserCard(
                id: $this->generateID(),
                userID: $userID,
                cardID: $item->id,
                amountPerSec: $item->amountPerSec,
                level: 1,
                totalExp: 0,
                createdAt: $requestAt,
                updatedAt: $requestAt,
            );
        }
        $placeholders = implode(',', array_fill(0, count($cards), '(?, ?, ?, ?, ?, ?, ?, ?)'));
        $query = 'INSERT INTO user_cards(id, user_id, card_id, amount_per_sec, level, total_exp, created_at, updated_at) VALUES ' . $placeholders;
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $position = 1;
        foreach ($cards as $card) {
            $stmt->bindValue($position++, $card->id, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->userID, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->cardID, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->amountPerSec, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->level, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->totalExp, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->createdAt, PDO::PARAM_INT);
            $stmt->bindValue($position++, $card->updatedAt, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    /** @param array{itemId: int, obtainAmount: int}[] $items */
    private function obtain45Items(int $userID, int $requestAt, array $items): void
    {
        if (!count($items)) {
            return;
        }
        $itemIds = [];
        foreach ($items as $item) {
            $itemIds[] = $item['itemId'];
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $query = "SELECT * FROM user_items WHERE user_id=? AND item_id IN ({$placeholders})";
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $userID, PDO::PARAM_INT);
        $position = 2;
        foreach ($itemIds as $itemId) {
            $stmt->bindValue($position++, $itemId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $userItems = [];
        while ($row = $stmt->fetch()) {
            $uitem = UserItem::fromDBRow($row);
            $userItems[$uitem->itemID] = $uitem;
        }

        $bulkUpserts = [];
        foreach ($items as $item) {
            $itemID = $item['itemId'];
            $obtainAmount = $item['obtainAmount'];
            // 所持数取得
            if (!isset($userItems[$itemID]) and !isset($bulkUpserts[$itemID])) { // 新規作成
                $uitemID = $this->generateID();
                $itemMaster = $this->masterCache->getItemMasterById($itemID);
                $uitem = new UserItem(
                    id: $uitemID,
                    userID: $userID,
                    itemType: $itemMaster->itemType,
                    itemID: $itemMaster->id,
                    amount: $obtainAmount,
                    createdAt: $requestAt,
                    updatedAt: $requestAt,
                );
                $bulkUpserts[$itemID] = $uitem;
            } else { // 更新
                $bulkUpserts[$itemID] ??= $userItems[$itemID];
                $bulkUpserts[$itemID]->amount += $obtainAmount;
                $bulkUpserts[$itemID]->updatedAt = $requestAt;
            }
        }

        if ($bulkUpserts) {
            $placeholders = implode(',', array_fill(0, count($bulkUpserts), '(?, ?, ?, ?, ?, ?, ?)'));
            $query = 'INSERT INTO user_items (id, user_id, item_type, item_id, amount, created_at, updated_at) VALUES ' . $placeholders .' ON DUPLICATE KEY UPDATE id = VALUES(id), user_id = VALUES(user_id), item_type = VALUES(item_type), item_id = VALUES(item_id), amount = VALUES(amount), created_at = VALUES(created_at), updated_at = VALUES(updated_at)';
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $position = 1;
            foreach ($bulkUpserts as $uitem) {
                $stmt->bindValue($position++, $uitem->id, PDO::PARAM_INT);
                $stmt->bindValue($position++, $userID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $uitem->itemType, PDO::PARAM_INT);
                $stmt->bindValue($position++, $uitem->itemID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $uitem->amount, PDO::PARAM_INT);
                $stmt->bindValue($position++, $uitem->createdAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $uitem->updatedAt, PDO::PARAM_INT);
            }
            $stmt->execute();
        }
    }

    /**
     * obtainItem アイテム付与処理
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    private function obtainItem(int $userID, int $itemID, int $itemType, int $obtainAmount, int $requestAt): void
    {
        switch ($itemType) {
            case 1: // coin
                $this->obtainCoin($userID, $obtainAmount);
                return;

            case 2: // card(ハンマー)
                $this->obtainCards($userID, $requestAt, [$itemID]);
                return;

            case 3:
            case 4: // 強化素材
                $this->obtain45Items($userID, $requestAt, [['itemId' => $itemID, 'obtainAmount' => $obtainAmount]]);
                break;

            default:
                throw new RuntimeException($this->errInvalidItemType);
        }

        return;
    }

    /**
     * initialize 初期化処理
     * POST /initialize
     */
    public function initialize(Request $request, Response $response): Response
    {
        $responses = [];
        foreach ($this->databaseManager->getDbHosts() as $databaseHost) {
            $responses[] = $this->httpClient->request('POST', "http://{$databaseHost}/initializeOne");
        }
        foreach ($responses as $responseFromDb) {
            $responseFromDb->getContent();
        }

        return $this->successResponse($response, new InitializeResponse(
            language: 'php',
        ));
    }
    /**
     * initializeOne 初期化処理
     * POST /initializeOne
     */
    public function initializeOne(Request $request, Response $response): Response
    {
        $fp = fopen('php://temp', 'w+');
        $descriptorSpec = [
            1 => $fp,
            2 => $fp,
        ];

        $process = proc_open(['/bin/sh', '-c', self::SQL_DIRECTORY . 'init.sh'], $descriptorSpec, $_);
        if ($process === false) {
            throw new HttpInternalServerErrorException($request, 'Failed to initialize: cannot open process');
        }

        if (proc_close($process) !== 0) {
            rewind($fp);
            $out = stream_get_contents($fp);
            throw new HttpInternalServerErrorException($request, sprintf('Failed to initialize: %s', $out));
        }

        return $this->successResponse($response, new InitializeResponse(
            language: 'php',
        ));
    }

    /**
     * createUser ユーザの作成
     * POST /user
     */
    public function createUser(Request $request, Response $response): Response
    {
        // parse body
        try {
            $req = new CreateUserRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        if ($req->viewerID === '' || $req->platformType < 1 || $req->platformType > 3) {
            throw new HttpBadRequestException($request, $this->errInvalidRequestBody);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $uID = $this->generateID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $this->databaseManager->selectDatabase($uID)->beginTransaction();

        // ユーザ作成
        $user = new User(
            id: $uID,
            isuCoin: 0,
            lastGetRewardAt: $requestAt,
            lastActivatedAt: $requestAt,
            registeredAt: $requestAt,
            createdAt: $requestAt,
            updatedAt: $requestAt,
        );
        $query = 'INSERT INTO users(id, last_activated_at, registered_at, last_getreward_at, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($uID)->prepare($query);
            $stmt->bindValue(1, $user->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $user->lastActivatedAt, PDO::PARAM_INT);
            $stmt->bindValue(3, $user->registeredAt, PDO::PARAM_INT);
            $stmt->bindValue(4, $user->lastGetRewardAt, PDO::PARAM_INT);
            $stmt->bindValue(5, $user->createdAt, PDO::PARAM_INT);
            $stmt->bindValue(6, $user->updatedAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $udID = $this->generateID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $userDevice = new UserDevice(
            id: $udID,
            userID: $user->id,
            platformID: $req->viewerID,
            platformType: $req->platformType,
            createdAt: $requestAt,
            updatedAt: $requestAt,
        );
        $query = 'INSERT INTO user_devices(id, user_id, platform_id, platform_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($uID)->prepare($query);
            $stmt->bindValue(1, $userDevice->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $user->id, PDO::PARAM_INT);
            $stmt->bindValue(3, $req->viewerID);
            $stmt->bindValue(4, $req->platformType, PDO::PARAM_INT);
            $stmt->bindValue(5, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(6, $requestAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // 初期デッキ付与
        $initCard = $this->masterCache->getItemMasterById(2);
        if ($initCard === null) {
            throw new HttpNotFoundException($request, $this->errItemNotFound);
        }

        /** @var list<UserCard> $initCards */
        $initCards = [];
        for ($i = 0; $i < 3; $i++) {
            try {
                $cID = $this->generateID();
            } catch (Exception $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }
            $card = new UserCard(
                id: $cID,
                userID: $user->id,
                cardID: $initCard->id,
                amountPerSec: $initCard->amountPerSec,
                level: 1,
                totalExp: 0,
                createdAt: $requestAt,
                updatedAt: $requestAt,
            );
            $initCards[] = $card;
        }
        $placeholders = implode(',', array_fill(0, count($initCards), '(?, ?, ?, ?, ?, ?, ?, ?)'));
        $query = 'INSERT INTO user_cards(id, user_id, card_id, amount_per_sec, level, total_exp, created_at, updated_at) VALUES ' . $placeholders;
        try {
            $stmt = $this->databaseManager->selectDatabase($uID)->prepare($query);
            $position = 1;
            foreach ($initCards as $card) {
                $stmt->bindValue($position++, $card->id, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->userID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->cardID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->amountPerSec, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->level, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->totalExp, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->createdAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $card->updatedAt, PDO::PARAM_INT);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $deckID = $this->generateID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $initDeck = new UserDeck(
            id: $deckID,
            userID: $user->id,
            cardID1: $initCards[0]->id,
            cardID2: $initCards[1]->id,
            cardID3: $initCards[2]->id,
            createdAt: $requestAt,
            updatedAt: $requestAt,
        );
        $query = 'INSERT INTO user_decks(id, user_id, user_card_id_1, user_card_id_2, user_card_id_3, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($uID)->prepare($query);
            $stmt->bindValue(1, $initDeck->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $initDeck->userID, PDO::PARAM_INT);
            $stmt->bindValue(3, $initDeck->cardID1, PDO::PARAM_INT);
            $stmt->bindValue(4, $initDeck->cardID2, PDO::PARAM_INT);
            $stmt->bindValue(5, $initDeck->cardID3, PDO::PARAM_INT);
            $stmt->bindValue(6, $initDeck->createdAt, PDO::PARAM_INT);
            $stmt->bindValue(7, $initDeck->updatedAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // ログイン処理
        try {
            [$user, $loginBonuses, $presents] = $this->loginProcess($user->id, $requestAt);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserNotFound || $err === $this->errItemNotFound || $err === $this->errLoginBonusRewardNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            } elseif ($err === $this->errInvalidItemType) {
                throw new HttpBadRequestException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        // generate session
        try {
            $sessID = $this->generateUUID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $sess = new Session(
            userID: $user->id,
            sessionID: "{$sessID}::{$user->id}",
        );
        $this->sessionStore->setSession($sess);

        $this->databaseManager->selectDatabase($uID)->commit();

        return $this->successResponse($response, new CreateUserResponse(
            userID: $user->id,
            viewerID: $req->viewerID,
            sessionID: $sess->sessionID,
            createdAt: $requestAt,
            updatedResources: new UpdatedResource($requestAt, $user, $userDevice, $initCards, [$initDeck], null, $loginBonuses, $presents),
        ));
    }

    /**
     * login ログイン
     * POST /login
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $req = new LoginRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $query = 'SELECT * FROM users WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($req->userID)->prepare($query);
            $stmt->bindValue(1, $req->userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, $this->errUserNotFound);
        }
        $user = User::fromDBRow($row);

        // check ban
        try {
            $isBan = $this->checkBan($user->id);
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($isBan) {
            throw new HttpForbiddenException($request, $this->errForbidden);
        }

        // viewer id check
        try {
            $this->checkViewerID($user->id, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        $this->databaseManager->selectDatabase($user->id)->beginTransaction();

        try {
            $sessID = $this->generateUUID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $sess = new Session(
            userID: $req->userID,
            sessionID: "{$sessID}::{$user->id}",
        );
        $this->sessionStore->setSession($sess);

        // すでにログインしているユーザはログイン処理をしない
        if ($this->isCompleteTodayLogin($user->lastActivatedAt, $requestAt)) {
            $user->updatedAt = $requestAt;
            $user->lastActivatedAt = $requestAt;

            $query = 'UPDATE users SET updated_at=?, last_activated_at=? WHERE id=?';
            try {
                $stmt = $this->databaseManager->selectDatabase($user->id)->prepare($query);
                $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
                $stmt->bindValue(2, $requestAt, PDO::PARAM_INT);
                $stmt->bindValue(3, $req->userID, PDO::PARAM_INT);
            } catch (PDOException $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }

            $this->databaseManager->selectDatabase($user->id)->commit();

            return $this->successResponse($response, new LoginResponse(
                viewerID: $req->viewerID,
                sessionID: $sess->sessionID,
                updatedResources: new UpdatedResource($requestAt, $user, null, null, null, null, null, null),
            ));
        }

        // login process
        try {
            [$user, $loginBonuses, $presents] = $this->loginProcess($req->userID, $requestAt);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserNotFound || $err === $this->errItemNotFound || $err === $this->errLoginBonusRewardNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            } elseif ($err === $this->errInvalidItemType) {
                throw new HttpBadRequestException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        $this->databaseManager->selectDatabase($user->id)->commit();

        return $this->successResponse($response, new LoginResponse(
            viewerID: $req->viewerID,
            sessionID: $sess->sessionID,
            updatedResources: new UpdatedResource($requestAt, $user, null, null, null, null, $loginBonuses, $presents),
        ));
    }

    /**
     * listGacha ガチャ一覧
     * GET /user/{userID}/gacha/index
     */
    public function listGacha(Request $request, Response $response): Response
    {
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, 'invalid userID parameter', $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        /** @var list<GachaMaster> $gachaMasterList */
        $gachaMasterList = $this->masterCache->getGachaMaster($requestAt);
        if (count($gachaMasterList) === 0) {
            return $this->successResponse($response, new ListGachaResponse( // 0 件
                oneTimeToken: '',
                gachas: [],
            ));
        }

        // ガチャ排出アイテム取得
        /** @var list<GachaData> $gachaDataList */
        $gachaDataList = [];
        try {
            foreach ($gachaMasterList as $v) {
                /** @var list<GachaItemMaster> $gachaItem */
                $gachaItem = $this->masterCache->getGachaItemMasterByID($v->id);
                if (count($gachaItem) === 0) {
                    throw new HttpNotFoundException($request, 'not found gacha item');
                }
                $gachaDataList[] = new GachaData(
                    gacha: $v,
                    gachaItem: $gachaItem,
                );
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // generate one time token
        $query = 'UPDATE user_one_time_tokens SET deleted_at=? WHERE user_id=? AND deleted_at IS NULL';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(2, $userID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        try {
            $tID = $this->generateID();
            $tk = $this->generateUUID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $token = new UserOneTimeToken(
            id: $tID,
            userID: $userID,
            token: $tk,
            tokenType: 1,
            createdAt: $requestAt,
            updatedAt: $requestAt,
            expiredAt: $requestAt + 600,
        );
        $query = 'INSERT INTO user_one_time_tokens(id, user_id, token, token_type, created_at, updated_at, expired_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $token->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $token->userID, PDO::PARAM_INT);
            $stmt->bindValue(3, $token->token);
            $stmt->bindValue(4, $token->tokenType, PDO::PARAM_INT);
            $stmt->bindValue(5, $token->createdAt, PDO::PARAM_INT);
            $stmt->bindValue(6, $token->updatedAt, PDO::PARAM_INT);
            $stmt->bindValue(7, $token->expiredAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        return $this->successResponse($response, new ListGachaResponse(
            oneTimeToken: $token->token,
            gachas: $gachaDataList,
        ));
    }

    /**
     * drawGacha ガチャを引く
     * POST /user/{userID}/gacha/draw/{gachaTypeID}/{n}
     */
    public function drawGacha(Request $request, Response $response): Response
    {
        $attributes = $request->getAttributes();
        $params = isset($attributes["__route__"]) ? ($attributes["__route__"])->getArguments() : [];
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        $gachaIDStr = $params['gachaID'] ?? '';
        $gachaID = filter_var($gachaIDStr, FILTER_VALIDATE_INT);
        if (!is_int($gachaID)) {
            throw new HttpBadRequestException($request, 'invalid gachaID');
        }

        $gachaCountStr = $params['n'] ?? '';
        $gachaCount = filter_var($gachaCountStr, FILTER_VALIDATE_INT);
        if (!is_int($gachaCount)) {
            throw new HttpBadRequestException($request);
        }
        if ($gachaCount !== 1 && $gachaCount !== 10) {
            throw new HttpBadRequestException($request, 'invalid draw gacha times');
        }

        try {
            $req = new DrawGachaRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $this->checkOneTimeToken($userID, $req->oneTimeToken, 1, $requestAt);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errInvalidToken) {
                throw new HttpBadRequestException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        try {
            $this->checkViewerID($userID, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        $consumedCoin = $gachaCount * 1000;

        // userのisuconが足りるか
        $query = 'SELECT * FROM users WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, $this->errUserNotFound);
        }
        $user = User::fromDBRow($row);
        if ($user->isuCoin < $consumedCoin) {
            throw new HttpException($request, 'not enough isucoin', StatusCodeInterface::STATUS_CONFLICT);
        }

        // gachaIDからガチャマスタの取得
        $gachaInfo = $this->masterCache->getGachaMasterByID($gachaID, $requestAt);
        if ($gachaInfo === null) {
            throw new HttpNotFoundException($request, 'not found gacha');
        }

        // gachaItemMasterからアイテムリスト取得
        /** @var list<GachaItemMaster> $gachaItemList */
        $gachaItemList = $this->masterCache->getGachaItemMasterByID($gachaID);
        if (count($gachaItemList) === 0) {
            throw new HttpNotFoundException($request, 'not found gacha item');
        }

        // weightの合計値を算出
        $sum = 0;
        foreach ($gachaItemList as $gachaItem) {
            $sum += $gachaItem->weight;
        }

        // random値の導出 & 抽選
        /** @var list<GachaItemMaster> $result */
        $result = [];
        for ($i = 0; $i < $gachaCount; $i++) {
            $random = random_int(0, (int)$sum);
            $boundary = 0;
            foreach ($gachaItemList as $v) {
                $boundary += $v->weight;
                if ($random < $boundary) {
                    $result[] = $v;
                    break;
                }
            }
        }

        $this->databaseManager->selectDatabase($userID)->beginTransaction();

        // 直付与 => プレゼントに入れる
        /** @var list<UserPresent> $presents */
        $presents = [];
        foreach ($result as $v) {
            try {
                $pID = $this->generateID();
            } catch (Exception $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }
            $present = new UserPresent(
                id: $pID,
                userID: $userID,
                sentAt: $requestAt,
                itemType: $v->itemType,
                itemID: $v->itemID,
                amount: $v->amount,
                presentMessage: sprintf('%sの付与アイテムです', $gachaInfo->name),
                createdAt: $requestAt,
                updatedAt: $requestAt,
            );

            $presents[] = $present;
        }

        $placeholders = implode(',', array_fill(0, count($presents), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
        $query = 'INSERT INTO user_presents(id, user_id, sent_at, item_type, item_id, amount, present_message, created_at, updated_at) VALUES ' . $placeholders;
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $position = 1;
            foreach ($presents as $present) {
                $stmt->bindValue($position++, $present->id, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->userID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->sentAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->itemType, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->itemID, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->amount, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->presentMessage);
                $stmt->bindValue($position++, $present->createdAt, PDO::PARAM_INT);
                $stmt->bindValue($position++, $present->updatedAt, PDO::PARAM_INT);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // isuconをへらす
        $query = 'UPDATE users SET isu_coin=? WHERE id=?';
        $totalCoin = $user->isuCoin - $consumedCoin;
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $totalCoin, PDO::PARAM_INT);
            $stmt->bindValue(2, $userID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $this->databaseManager->selectDatabase($userID)->commit();

        return $this->successResponse($response, new DrawGachaResponse(
            presents: $presents,
        ));
    }

    /**
     * listPresent プレゼント一覧
     * GET /user/{userID}/present/index/{n}
     */
    public function listPresent(Request $request, Response $response): Response
    {
        $attributes = $request->getAttributes();
        $params = isset($attributes["__route__"]) ? ($attributes["__route__"])->getArguments() : [];
        $nStr = $params['n'] ?? '';
        $n = filter_var($nStr, FILTER_VALIDATE_INT);
        if (!is_int($n)) {
            throw new HttpBadRequestException($request, 'invalid n parameter');
        }
        if ($n === 0) {
            throw new HttpBadRequestException($request, 'index number is more than 1');
        }

        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, 'invalid userID parameter', $e);
        }

        $offset = self::PRESENT_COUNT_PER_PAGE * ($n - 1);
        /** @var list<UserPresent> $presentList */
        $presentList = [];
        $query = <<<'SQL'
            SELECT * FROM user_presents
            WHERE user_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC, id
            LIMIT ? OFFSET ?
        SQL;
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->bindValue(2, self::PRESENT_COUNT_PER_PAGE + 1, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $presentList[] = UserPresent::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $isNext = count($presentList) === self::PRESENT_COUNT_PER_PAGE + 1;
        if ($isNext) {
            unset($presentList[self::PRESENT_COUNT_PER_PAGE]);
        }

        return $this->successResponse($response, new ListPresentResponse(
            presents: $presentList,
            isNext: $isNext,
        ));
    }

    /**
     * receivePresent プレゼント受け取り
     * POST /user/{userID}/present/receive
     */
    public function receivePresent(Request $request, Response $response): Response
    {
        // read body
        try {
            $req = new ReceivePresentRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        if (count($req->presentIDs) === 0) {
            throw new HttpException($request, 'presentIds is empty', StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->checkViewerID($userID, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        // user_presentsに入っているが未取得のプレゼント取得
        $inClause = str_repeat('?, ', count($req->presentIDs) - 1) . '?';
        $query = 'SELECT * FROM user_presents WHERE id IN (' . $inClause . ') AND deleted_at IS NULL';
        /** @var list<UserPresent> $obtainPresent */
        $obtainPresent = [];
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            foreach ($req->presentIDs as $i => $presentID) {
                $stmt->bindValue($i + 1, $presentID, PDO::PARAM_INT);
            }
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $obtainPresent[] = UserPresent::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if (count($obtainPresent) === 0) {
            return $this->successResponse($response, new ReceivePresentResponse(
                updatedResources: new UpdatedResource($requestAt, null, null, null, null, null, null, []),
            ));
        }

        $this->databaseManager->selectDatabase($userID)->beginTransaction();
        // 配布処理
        $presentIDs = [];
        $coinAmount = 0;
        $cardIDs = [];
        $item45s = [];
        for ($i = 0; $i < count($obtainPresent); $i++) {
            $presentIDs[] = $obtainPresent[$i]->id;
            $obtainPresent[$i]->updatedAt = $requestAt;
            $obtainPresent[$i]->deletedAt = $requestAt;
            switch ($obtainPresent[$i]->itemType) {
                case 1:
                    $coinAmount += $obtainPresent[$i]->amount;
                    break;
                case 2:
                    $cardIDs[] = $obtainPresent[$i]->itemID;
                    break;
                default:
                    $item45s[] = ['itemId' => $obtainPresent[$i]->itemID, 'obtainAmount' => $obtainPresent[$i]->amount];
            }
        }
        if (count($presentIDs)) {
            $placeholders = implode(',', array_fill(0, count($presentIDs), '?'));
            $query = "UPDATE user_presents SET deleted_at=?, updated_at=? WHERE id IN ({$placeholders})";
            try {
                $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
                $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
                $stmt->bindValue(2, $requestAt, PDO::PARAM_INT);
                $position = 3;
                foreach ($presentIDs as $presentID) {
                    $stmt->bindValue($position++, $presentID, PDO::PARAM_INT);
                }
                $stmt->execute();
                $this->obtainCoin($userID, $coinAmount);
                $this->obtainCards($userID, $requestAt, $cardIDs);
                $this->obtain45Items($userID, $requestAt, $item45s);
            } catch (PDOException $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }
        }


        $this->databaseManager->selectDatabase($userID)->commit();

        return $this->successResponse($response, new ReceivePresentResponse(
            updatedResources: new UpdatedResource($requestAt, null, null, null, null, null, null, $obtainPresent),
        ));
    }

    /**
     * listItem アイテムリスト
     * GET /user/{userID}/item
     */
    public function listItem(Request $request, Response $response): Response
    {
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $query = 'SELECT * FROM users WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, $this->errUserNotFound);
        }
        $user = User::fromDBRow($row);

        /** @var list<UserItem> $itemList */
        $itemList = [];
        $query = 'SELECT * FROM user_items WHERE user_id = ?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $itemList[] = UserItem::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        /** @var list<UserCard> $cardList */
        $cardList = [];
        $query = 'SELECT * FROM user_cards WHERE user_id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $cardList[] = UserCard::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // generate one time token
        $query = 'UPDATE user_one_time_tokens SET deleted_at=? WHERE user_id=? AND deleted_at IS NULL';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(2, $userID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        try {
            $tID = $this->generateID();
            $tk = $this->generateUUID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $token = new UserOneTimeToken(
            id: $tID,
            userID: $userID,
            token: $tk,
            tokenType: 2,
            createdAt: $requestAt,
            updatedAt: $requestAt,
            expiredAt: $requestAt + 600,
        );
        $query = 'INSERT INTO user_one_time_tokens(id, user_id, token, token_type, created_at, updated_at, expired_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $token->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $token->userID, PDO::PARAM_INT);
            $stmt->bindValue(3, $token->token);
            $stmt->bindValue(4, $token->tokenType, PDO::PARAM_INT);
            $stmt->bindValue(5, $token->createdAt, PDO::PARAM_INT);
            $stmt->bindValue(6, $token->updatedAt, PDO::PARAM_INT);
            $stmt->bindValue(7, $token->expiredAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        return $this->successResponse($response, new ListItemResponse(
            oneTimeToken: $token->token,
            items: $itemList,
            user: $user,
            cards: $cardList,
        ));
    }

    /**
     * addExpToCard 装備強化
     * POST /user/{userID}/card/addexp/{cardID}
     */
    public function addExpToCard(Request $request, Response $response): Response
    {
        $attributes = $request->getAttributes();
        $params = isset($attributes["__route__"]) ? ($attributes["__route__"])->getArguments() : [];
        $request->getAttribute('cardID');
        $cardIDStr = $params['cardID'] ?? '';
        $cardID = filter_var($cardIDStr, FILTER_VALIDATE_INT);
        if (!is_int($cardID)) {
            throw new HttpBadRequestException($request);
        }

        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        // read body
        try {
            $req = new AddExpToCardRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $this->checkOneTimeToken($userID, $req->oneTimeToken, 2, $requestAt);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errInvalidToken) {
                throw new HttpBadRequestException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        try {
            $this->checkViewerID($userID, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        // get target card
        $query = <<<'SQL'
            SELECT uc.id , uc.user_id , uc.card_id , uc.amount_per_sec , uc.level, uc.total_exp, im.amount_per_sec as 'base_amount_per_sec', im.max_level , im.max_amount_per_sec , im.base_exp_per_level
            FROM user_cards as uc
            INNER JOIN item_masters as im ON uc.card_id = im.id
            WHERE uc.id = ? AND uc.user_id=?
        SQL;
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $cardID, PDO::PARAM_INT);
            $stmt->bindValue(2, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request);
        }
        $card = TargetUserCardData::fromDBRow($row);

        if ($card->level === $card->maxLevel) {
            throw new HttpBadRequestException($request, 'target card is max level');
        }

        // 消費アイテムの所持チェック
        /** @var list<ConsumeUserItemData> $items */
        $items = [];
        $query = <<<'SQL'
            SELECT ui.id, ui.user_id, ui.item_id, ui.item_type, ui.amount, ui.created_at, ui.updated_at, im.gained_exp
            FROM user_items as ui
            INNER JOIN item_masters as im ON ui.item_id = im.id
            WHERE ui.item_type = 3 AND ui.id=? AND ui.user_id=?
        SQL;
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            foreach ($req->items as $v) {
                $v = (object)$v;
                $stmt->bindValue(1, $v->id, PDO::PARAM_INT);
                $stmt->bindValue(2, $userID, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch();
                if ($row === false) {
                    throw new HttpNotFoundException($request);
                }
                $item = ConsumeUserItemData::fromDBRow($row);

                if ($v->amount > $item->amount) {
                    throw new HttpBadRequestException($request, 'item not enough');
                }
                $item->consumeAmount = $v->amount;
                $items[] = $item;
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // 経験値付与
        // 経験値をカードに付与
        foreach ($items as $v) {
            $card->totalExp += $v->gainedExp * $v->consumeAmount;
        }

        // lvup判定(lv upしたら生産性を加算)
        while (true) {
            $nextLvThreshold = $card->baseExpPerLevel * pow(1.2, $card->level - 1);
            if ($nextLvThreshold > $card->totalExp) {
                break;
            }

            // lv up処理
            $card->level += 1;
            $card->amountPerSec += ($card->maxAmountPerSec - $card->baseAmountPerSec) / ($card->maxLevel - 1);
        }

        $this->databaseManager->selectDatabase($userID)->beginTransaction();

        // cardのlvと経験値の更新、itemの消費
        $query = 'UPDATE user_cards SET amount_per_sec=?, level=?, total_exp=?, updated_at=? WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $card->amountPerSec, PDO::PARAM_INT);
            $stmt->bindValue(2, $card->level, PDO::PARAM_INT);
            $stmt->bindValue(3, $card->totalExp, PDO::PARAM_INT);
            $stmt->bindValue(4, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(5, $card->id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $query = 'UPDATE user_items SET amount=?, updated_at=? WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            foreach ($items as $v) {
                $stmt->bindValue(1, $v->amount - $v->consumeAmount, PDO::PARAM_INT);
                $stmt->bindValue(2, $requestAt, PDO::PARAM_INT);
                $stmt->bindValue(3, $v->id, PDO::PARAM_INT);
                $stmt->execute();
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // get response data
        $query = 'SELECT * FROM user_cards WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $cardID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, 'not found card');
        }
        $resultCard = UserCard::fromDBRow($row);
        /** @var list<UserItem> $resultItems */
        $resultItems = [];
        foreach ($items as $v) {
            $resultItems[] = new UserItem(
                id: $v->id,
                userID: $v->userID,
                itemID: $v->itemID,
                itemType: $v->itemType,
                amount: $v->amount - $v->consumeAmount,
                createdAt: $v->createdAt,
                updatedAt: $requestAt,
            );
        }

        $this->databaseManager->selectDatabase($userID)->commit();

        return $this->successResponse($response, new AddExpToCardResponse(
            updatedResources: new UpdatedResource($requestAt, null, null, [$resultCard], null, $resultItems, null, null),
        ));
    }

    /**
     * updateDeck 装備変更
     * POST /user/{userID}/card
     */
    public function updateDeck(Request $request, Response $response): Response
    {
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        // read body
        try {
            $req = new UpdateDeckRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        if (count($req->cardIDs) !== self::DECK_CARD_NUMBER) {
            throw new HttpBadRequestException($request, 'invalid number of cards');
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $this->checkViewerID($userID, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        // カード所持情報のバリデーション
        /** @var list<UserCard> $cards */
        $cards = [];
        $query = 'SELECT * FROM user_cards WHERE id IN (?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $req->cardIDs[0], PDO::PARAM_INT);
            $stmt->bindValue(2, $req->cardIDs[1], PDO::PARAM_INT);
            $stmt->bindValue(3, $req->cardIDs[2], PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $cards[] = UserCard::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if (count($cards) !== self::DECK_CARD_NUMBER) {
            throw new HttpBadRequestException($request, 'invalid card ids');
        }

        $this->databaseManager->selectDatabase($userID)->beginTransaction();

        // update data
        $query = 'UPDATE user_decks SET updated_at=?, deleted_at=? WHERE user_id=? AND deleted_at IS NULL';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(2, $requestAt, PDO::PARAM_INT);
            $stmt->bindValue(3, $userID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $udID = $this->generateID();
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $newDeck = new UserDeck(
            id: $udID,
            userID: $userID,
            cardID1: $req->cardIDs[0],
            cardID2: $req->cardIDs[1],
            cardID3: $req->cardIDs[2],
            createdAt: $requestAt,
            updatedAt: $requestAt,
        );
        $query = 'INSERT INTO user_decks(id, user_id, user_card_id_1, user_card_id_2, user_card_id_3, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $newDeck->id, PDO::PARAM_INT);
            $stmt->bindValue(2, $newDeck->userID, PDO::PARAM_INT);
            $stmt->bindValue(3, $newDeck->cardID1, PDO::PARAM_INT);
            $stmt->bindValue(4, $newDeck->cardID2, PDO::PARAM_INT);
            $stmt->bindValue(5, $newDeck->cardID3, PDO::PARAM_INT);
            $stmt->bindValue(6, $newDeck->createdAt, PDO::PARAM_INT);
            $stmt->bindValue(7, $newDeck->updatedAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        $this->databaseManager->selectDatabase($userID)->commit();

        return $this->successResponse($response, new UpdateDeckResponse(
            updatedResources: new UpdatedResource($requestAt, null, null, null, [$newDeck], null, null, null),
        ));
    }

    /**
     * reward ゲーム報酬受取
     * POST /user/{userID}/reward
     */
    public function reward(Request $request, Response $response): Response
    {
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        // parse body
        try {
            $req = new RewardRequest((object)$request->getParsedBody());
        } catch (Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        try {
            $this->checkViewerID($userID, $req->viewerID);
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($err === $this->errUserDeviceNotFound) {
                throw new HttpNotFoundException($request, $err, $e);
            }
            throw new HttpInternalServerErrorException($request, $err, $e);
        }

        // 最後に取得した報酬時刻取得
        $query = 'SELECT * FROM users WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, $this->errUserNotFound);
        }
        $user = User::fromDBRow($row);

        // 使っているデッキの取得
        $query = 'SELECT * FROM user_decks WHERE user_id=? AND deleted_at IS NULL';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request);
        }
        $deck = UserDeck::fromDBRow($row);

        /** @var list<UserCard> $cards */
        $cards = [];
        $query = 'SELECT * FROM user_cards WHERE id IN (?, ?, ?)';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $deck->cardID1, PDO::PARAM_INT);
            $stmt->bindValue(2, $deck->cardID2, PDO::PARAM_INT);
            $stmt->bindValue(3, $deck->cardID3, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $cards[] = UserCard::fromDBRow($row);
            }
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if (count($cards) !== 3) {
            throw new HttpBadRequestException($request, 'invalid cards length');
        }

        // 経過時間*生産性のcoin (1椅子 = 1coin)
        $pastTime = $requestAt - $user->lastGetRewardAt;
        $getCoin = $pastTime * ($cards[0]->amountPerSec + $cards[1]->amountPerSec + $cards[2]->amountPerSec);

        // 報酬の保存(ゲームない通貨を保存)(users)
        $user->isuCoin += $getCoin;
        $user->lastGetRewardAt = $requestAt;

        $query = 'UPDATE users SET isu_coin=?, last_getreward_at=? WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $user->isuCoin, PDO::PARAM_INT);
            $stmt->bindValue(2, $user->lastGetRewardAt, PDO::PARAM_INT);
            $stmt->bindValue(3, $user->id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        return $this->successResponse($response, new RewardResponse(
            updateResources: new UpdatedResource($requestAt, $user, null, null, null, null, null, null),
        ));
    }

    /**
     * home ホーム取得
     * GET /user/{userID}/home
     */
    public function home(Request $request, Response $response): Response
    {
        try {
            $userID = $this->getUserID($request);
        } catch (RuntimeException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        try {
            $requestAt = $this->getRequestTime($request);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }

        // 装備情報
        $deck = null;
        $query = 'SELECT * FROM user_decks WHERE user_id=? AND deleted_at IS NULL';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row !== false) {
            $deck = UserDeck::fromDBRow($row);
        }

        // 生産性
        /** @var list<UserCard> $cards */
        $cards = [];
        if (!is_null($deck)) {
            $query = 'SELECT * FROM user_cards WHERE id IN (?, ?, ?)';
            try {
                $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
                $stmt->bindValue(1, $deck->cardID1, PDO::PARAM_INT);
                $stmt->bindValue(2, $deck->cardID2, PDO::PARAM_INT);
                $stmt->bindValue(3, $deck->cardID3, PDO::PARAM_INT);
                $stmt->execute();
                while ($row = $stmt->fetch()) {
                    $cards[] = UserCard::fromDBRow($row);
                }
            } catch (PDOException $e) {
                throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
            }
        }
        $totalAmountPerSec = 0;
        foreach ($cards as $v) {
            $totalAmountPerSec += $v->amountPerSec;
        }

        // 経過時間
        $query = 'SELECT * FROM users WHERE id=?';
        try {
            $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
            $stmt->bindValue(1, $userID, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if ($row === false) {
            throw new HttpNotFoundException($request, $this->errUserNotFound);
        }
        $user = User::fromDBRow($row);
        $pastTime = $requestAt - $user->lastGetRewardAt;

        return $this->successResponse($response, new HomeResponse(
            now: $requestAt,
            user: $user,
            deck: $deck,
            totalAmountPerSec: $totalAmountPerSec,
            pastTime: $pastTime,
        ));
    }

    // //////////////////////////////////////
    // util

    /**
     * health ヘルスチェック
     */
    public function health(Request $request, Response $response): Response
    {
        $response->getBody()->write('OK');

        return $response;
    }
}
