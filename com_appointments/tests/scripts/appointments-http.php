<?php

final class AppointmentsHttp
{
    public int $status = 0;
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
        ]);

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

    public function token(string $html): string
    {
        if (!preg_match('/name="([a-f0-9]{32})"\s+value="1"/', $html, $match)) {
            throw new RuntimeException('No form token was rendered.');
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
        $html = $this->request('/index.php?option=com_appointments&view=eventtype&id=' . $event
            . '&timezone=UTC&month=' . substr($start, 0, 7) . '&day=' . substr($start, 0, 10));

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
                'booking' => array_replace([
                    'name' => 'Fixture Invitee', 'email' => 'invitee@example.test', 'timezone' => 'UTC', 'start' => $start,
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
