<?php

declare(strict_types=1);

namespace App\Services\Nntp;

enum ResponseCode: int
{
    case ReadyPostingAllowed = 200;
    case ReadyPostingProhibited = 201;
    case ClosingConnection = 205;
    case GroupSelected = 211;
    case ArticleFollows = 220;
    case HeadFollows = 221;
    case BodyFollows = 222;
    case OverviewFollows = 224;
    case HeaderFollows = 225;
    case AuthenticationAccepted = 281;
    case CompressionEnabled = 290;
    case AuthenticationContinue = 381;
    case ServiceDiscontinued = 400;
    case NoSuchGroup = 411;
    case NoGroupSelected = 412;
    case NoArticleSelected = 420;
    case NoSuchArticleNumber = 423;
    case NoSuchArticleId = 430;
    case AuthenticationRequired = 480;
    case AuthenticationRejected = 482;
    case UnknownCommand = 500;
    case SyntaxError = 501;
    case NotPermitted = 502;
    case NotSupported = 503;

    public function description(): string
    {
        return match ($this) {
            self::ReadyPostingAllowed => 'Server ready - posting allowed',
            self::ReadyPostingProhibited => 'Server ready - posting prohibited',
            self::ClosingConnection => 'Server closing connection',
            self::GroupSelected => 'Group selected',
            self::ArticleFollows => 'Article follows',
            self::HeadFollows => 'Headers follow',
            self::BodyFollows => 'Body follows',
            self::OverviewFollows => 'Overview follows',
            self::HeaderFollows => 'Header values follow',
            self::AuthenticationAccepted => 'Authentication accepted',
            self::CompressionEnabled => 'Compression enabled',
            self::AuthenticationContinue => 'Authentication password required',
            self::ServiceDiscontinued => 'Service discontinued',
            self::NoSuchGroup => 'No such group',
            self::NoGroupSelected => 'No group selected',
            self::NoArticleSelected => 'No article selected',
            self::NoSuchArticleNumber => 'No such article number',
            self::NoSuchArticleId => 'No such article',
            self::AuthenticationRequired => 'Authentication required',
            self::AuthenticationRejected => 'Authentication rejected',
            self::UnknownCommand => 'Unknown command',
            self::SyntaxError => 'Command syntax error',
            self::NotPermitted => 'Command not permitted',
            self::NotSupported => 'Command not supported',
        };
    }
}
