<?php

require_once __DIR__ . '/appointments-menu.php';

final class AppointmentsHttp
{
    public int $status = 0;
    /** The Location headers of the redirects the last request followed, in order. */
    public array $locations = [];
    private string $cookie;

    public function __construct(private string $base = 'http://localhost', string $name = 'appointments')
    {
        $this->cookie = sys_get_temp_dir() . '/suite-' . $name . '-' . bin2hex(random_bytes(8)) . '.cookies';
    }

    public function __destruct()
    {
        if (is_file($this->cookie)) {
            unlink($this->cookie);
        }
    }

    public function request(string $path, ?array $data = null): string
    {
        $curl = $this->handle($path, $data);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $this->status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        return $body;
    }

    private function handle(string $path, ?array $data): CurlHandle
    {
        $curl = curl_init(rtrim($this->base, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
                if (preg_match('/^Location:\s*(\S.*?)\s*$/i', $line, $match)) {
                    $this->locations[] = $match[1];
                }

                return strlen($line);
            },
        ]);
        $this->locations = [];

        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        return $curl;
    }

    /** Run independent browser sessions simultaneously, optionally observing an external DB lock. */
    public static function parallel(array $requests, ?callable $progress = null): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $bodies = [];

        try {
            foreach ($requests as [$client, $path, $data]) {
                $handle = $client->handle($path, $data);
                curl_multi_add_handle($multi, $handle);
                $handles[] = [$client, $handle];
            }

            do {
                $result = curl_multi_exec($multi, $running);

                if ($result !== CURLM_OK) {
                    throw new RuntimeException('Concurrent HTTP execution failed.');
                }

                if ($progress !== null) {
                    $progress($running);
                }

                if ($running && curl_multi_select($multi, .05) === -1) {
                    usleep(10000);
                }
            } while ($running);

            foreach ($handles as [$client, $handle]) {
                if (curl_errno($handle)) {
                    throw new RuntimeException('Concurrent HTTP request failed: ' . curl_error($handle));
                }

                $client->status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $bodies[] = curl_multi_getcontent($handle);
            }
        } finally {
            foreach ($handles as [$client, $handle]) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }

        return $bodies;
    }

    /**
     * Whether the last request ended at a completed result: the redirect carried the fragment to
     * its container, the page has that container, a focusable status region and the script that
     * focuses it; without a target, neither a result fragment nor a result (a refused form).
     */
    public function landedAt(string $html, ?string $target): bool
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $results = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " com-appointments-status ")]'
            . '[@role="status"][@tabindex="-1"][@autofocus][@data-appointments-result]');
        $fragments = array_filter($this->locations, static fn (string $location): bool => (bool) preg_match('/#appointment-(result|confirmation)$/', $location));

        if ($target === null) {
            return $fragments === [] && $xpath->query('//*[@data-appointments-result]')->length === 0;
        }

        return $this->locations !== [] && str_ends_with(end($this->locations), '#' . $target)
            && $xpath->query('//*[@id="' . $target . '"]//p[@data-appointments-result]')->length === 1
            && $results->length === 1
            && preg_match('#/media/com_appointments/js/booking(\.min)?\.js#', $html) === 1;
    }

    public function token(string $html): string
    {
        if (!preg_match('/name="([a-f0-9]{32})"\s+value="1"/', $html, $match)) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
            throw new RuntimeException('No form token was rendered: HTTP ' . $this->status . ', '
                . basename($caller['file']) . ':' . $caller['line'] . '.');
        }

        return $match[1];
    }

    public function login(): void
    {
        $token = $this->token($this->request('/administrator/index.php'));
        $html = $this->request('/administrator/index.php', [
            'username' => getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin',
            'passwd' => getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#',
            'option' => 'com_login', 'task' => 'login', 'return' => base64_encode('index.php'), $token => 1,
        ]);

        if (!str_contains($html, 'task=logout') && !str_contains($html, 'com_cpanel')) {
            throw new RuntimeException('Administrator login failed.');
        }
    }

    public function bookingForm(int $event, string $start, array $details = []): array
    {
        // The booking page through its menu item, the only way it opens (appointments-menu.php).
        $html = $this->request(appointments_page($event, '&timezone=UTC&start=' . urlencode($start)));

        if ($this->status !== 200 || !str_contains($html, 'name="booking[start]"')) {
            throw new RuntimeException('The public booking form did not render: HTTP ' . $this->status . '.');
        }

        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $hidden = [];

        foreach ($xpath->query('//input[@type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return [
            $this, '/index.php?option=com_appointments&task=booking.submit&id=' . $event,
            [
                $this->token($html) => 1, 'rendered_at' => $hidden['rendered_at'] ?? '',
                'form_signature' => $hidden['form_signature'] ?? '', 'website' => '', 'consent' => 1,
        'consent_version' => $hidden['consent_version'] ?? '',
                'booking' => array_replace([
                    'first_name' => 'Fixture', 'last_name' => 'Invitee', 'email' => 'invitee@example.test', 'timezone' => 'UTC', 'start' => $start,
                ], $details),
            ],
        ];
    }

    public function submit(string $entity, array $form, string $task = 'save'): string
    {
        $id = (int) ($form['id'] ?? 0);
        $html = $this->request('/administrator/index.php?option=com_appointments&task=' . $entity . ($id ? '.edit&id=' . $id : '.add'));
        $token = $this->token($html);

        return $this->request('/administrator/index.php?option=com_appointments&id=' . $id, [
            'task' => $entity . '.' . $task, 'jform' => $form, $token => 1,
        ]);
    }
}
