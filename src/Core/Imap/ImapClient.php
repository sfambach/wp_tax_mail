<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

/**
 * The few IMAP commands the import needs (RFC 9051 / 3501): log in, list and create folders, find new mails by UID,
 * fetch one without marking it read, move it.
 *
 * ```mermaid
 * flowchart LR
 *   C["command()"] -->|"A7 UID FETCH …"| S["server"]
 *   S -->|"* untagged … {n} literal"| C
 *   S -->|"A7 OK / NO / BAD"| C
 * ```
 *
 * Every command waits for its own tagged answer; untagged lines before it are collected with their literals
 * inlined in order, which is all the parsing below relies on.
 */
final class ImapClient
{
    private int $tagCounter = 0;

    /** @var list<string>|null */
    private ?array $capabilities = null;

    private ?string $delimiter = null;

    public function __construct(private readonly ImapStream $stream)
    {
        $greeting = $this->stream->readLine();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new ImapException('unexpected greeting: ' . $greeting);
        }
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
        $this->capabilities = null;
    }

    /** @return list<string> upper-case capability names */
    public function capabilities(): array
    {
        if ($this->capabilities === null) {
            $this->capabilities = [];

            foreach ($this->command('CAPABILITY') as $response) {
                if (str_starts_with(strtoupper($response->text), 'CAPABILITY ')) {
                    $names = preg_split('/\s+/', trim(substr($response->text, 11))) ?: [];
                    $this->capabilities = array_values(array_map('strtoupper', $names));
                }
            }
        }

        return $this->capabilities;
    }

    public function canMove(): bool
    {
        $capabilities = $this->capabilities();

        return in_array('MOVE', $capabilities, true) || in_array('UIDPLUS', $capabilities, true);
    }

    /** The server's hierarchy separator, so «Taxmod/Importiert» becomes «Taxmod.Importiert» where the server wants dots. */
    public function delimiter(): string
    {
        if ($this->delimiter === null) {
            $this->delimiter = '/';

            foreach ($this->command('LIST "" ""') as $response) {
                if (preg_match('/^LIST \([^)]*\) "(.)"/i', $response->text, $match) === 1) {
                    $this->delimiter = $match[1];
                }
            }
        }

        return $this->delimiter;
    }

    /** @return list<string> folder names, decoded to UTF-8 */
    public function folders(): array
    {
        $names = [];

        foreach ($this->command('LIST "" "*"') as $response) {
            if (preg_match('/^LIST \([^)]*\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.*)$/i', $response->text, $match) !== 1) {
                continue;
            }

            $name = $response->literals !== [] ? $response->literals[0] : self::unquote($match[1]);
            $names[] = self::decodeFolderName($name);
        }

        return $names;
    }

    public function createFolder(string $name): void
    {
        $this->command('CREATE ' . self::quote(self::encodeFolderName($name)));
    }

    public function select(string $folder): MailboxStatus
    {
        $count = 0;
        $uidValidity = 0;
        $uidNext = 0;

        foreach ($this->command('SELECT ' . self::quote(self::encodeFolderName($folder))) as $response) {
            if (preg_match('/^(\d+) EXISTS$/i', $response->text, $match) === 1) {
                $count = (int) $match[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/i', $response->text, $match) === 1) {
                $uidValidity = (int) $match[1];
            } elseif (preg_match('/UIDNEXT (\d+)/i', $response->text, $match) === 1) {
                $uidNext = (int) $match[1];
            }
        }

        return new MailboxStatus($count, $uidValidity, $uidNext);
    }

    /**
     * UIDs above the last one seen, ascending.
     *
     * @return list<int>
     */
    public function uidsAfter(int $lastSeenUid): array
    {
        $uids = [];

        foreach ($this->command(sprintf('UID SEARCH UID %d:*', $lastSeenUid + 1)) as $response) {
            if (str_starts_with(strtoupper($response->text), 'SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($response->text, 6))) ?: [] as $uid) {
                    // «n:*» always matches the highest UID, even when it is below n.
                    if ($uid !== '' && (int) $uid > $lastSeenUid) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /** Finds a mail again after the server renumbered its UIDs (UIDVALIDITY changed); null when it is gone. */
    public function uidByMessageId(string $messageId): ?int
    {
        foreach ($this->command('UID SEARCH HEADER Message-ID ' . self::quote($messageId)) as $response) {
            if (preg_match('/^SEARCH (\d+)/i', $response->text, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /** The whole mail as the server stores it; PEEK keeps it unread. */
    public function fetchRaw(int $uid): string
    {
        foreach ($this->command(sprintf('UID FETCH %d (BODY.PEEK[])', $uid)) as $response) {
            if ($response->literals !== [] && preg_match('/^\d+ FETCH /i', $response->text) === 1) {
                return $response->literals[0];
            }
        }

        throw new ImapException(sprintf('mail with UID %d not found', $uid));
    }

    public function move(int $uid, string $folder): void
    {
        $target = self::quote(self::encodeFolderName($folder));

        if (in_array('MOVE', $this->capabilities(), true)) {
            $this->command(sprintf('UID MOVE %d %s', $uid, $target));

            return;
        }

        // A plain EXPUNGE would also remove every other mail somebody flagged \Deleted, so without UIDPLUS: refuse.
        if (! in_array('UIDPLUS', $this->capabilities(), true)) {
            throw new ImapException('server supports neither MOVE nor UIDPLUS');
        }

        $this->command(sprintf('UID COPY %d %s', $uid, $target));
        $this->command(sprintf('UID STORE %d +FLAGS.SILENT (\\Deleted)', $uid));
        $this->command(sprintf('UID EXPUNGE %d', $uid));
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } finally {
            $this->stream->close();
        }
    }

    /** @return list<UntaggedResponse> */
    private function command(string $command): array
    {
        $tag = 'A' . (++$this->tagCounter);
        $this->stream->writeLine($tag . ' ' . $command);

        $responses = [];

        while (true) {
            [$line, $literals] = $this->readLogicalLine();

            if (str_starts_with($line, '* ')) {
                $responses[] = new UntaggedResponse(substr($line, 2), $literals);
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                $status = substr($line, strlen($tag) + 1);

                if (strncasecmp($status, 'OK', 2) !== 0) {
                    // The LOGIN line carries the password; name the verb only.
                    throw new ImapException(strtok($command, ' ') . ' refused: ' . $status);
                }

                return $responses;
            }

            // Continuation requests («+ …») are not used by any command here; anything else is noise.
        }
    }

    /** @return array{0: string, 1: list<string>} the line with literals cut out, and the literals in order */
    private function readLogicalLine(): array
    {
        $line = '';
        $literals = [];

        while (true) {
            $part = $this->stream->readLine();

            if (preg_match('/\{(\d+)\}$/', $part, $match) !== 1) {
                return [$line . $part, $literals];
            }

            $line .= substr($part, 0, -strlen($match[0]));
            $literals[] = $this->stream->readBytes((int) $match[1]);
        }
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new ImapException('line breaks are not allowed in an IMAP string');
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    private static function unquote(string $value): string
    {
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private static function encodeFolderName(string $name): string
    {
        return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    private static function decodeFolderName(string $name): string
    {
        return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    }
}
