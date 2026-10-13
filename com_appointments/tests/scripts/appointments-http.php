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
        $curl = curl_init(rtrim($this->base, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
        ]);

        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $this->status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        return $body;
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
