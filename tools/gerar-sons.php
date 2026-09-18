<?php
// tools/gerar-sons.php — sintetiza os 4 sons de aviso (WAV 16 bits mono, 22,05 kHz) em public/assets/sounds/.
// Uso: php tools/gerar-sons.php   (já executado; os arquivos gerados vão no repositório)
declare(strict_types=1);

$rate = 22050;
$out = dirname(__DIR__) . '/public/assets/sounds';
if (!is_dir($out)) {
    mkdir($out, 0755, true);
}

/** Gera uma sequência de notas [freq Hz, duração s, volume 0..1] com envelope suave. */
function tone(array $notes, int $rate): string
{
    $samples = [];
    foreach ($notes as [$freq, $dur, $vol]) {
        $n = (int) ($dur * $rate);
        for ($i = 0; $i < $n; $i++) {
            $t = $i / $rate;
            $env = min(1.0, $i / ($rate * 0.01)) * min(1.0, ($n - $i) / ($rate * 0.06)); // ataque 10 ms, decaimento 60 ms
            $v = $freq > 0 ? sin(2 * M_PI * $freq * $t) * 0.8 + sin(4 * M_PI * $freq * $t) * 0.2 : 0.0; // fundamental + harmônico
            $samples[] = (int) round($v * $env * $vol * 32767 * 0.6);
        }
    }
    $data = pack('v*', ...array_map(static fn(int $s): int => $s < 0 ? $s + 65536 : $s, $samples));
    $header = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data));
    return $header . $data;
}

$sounds = [
    'entrada'   => [[523.25, 0.12, 0.7], [659.25, 0.12, 0.7], [783.99, 0.22, 0.8]],                      // dó-mi-sol subindo
    'saida'     => [[659.25, 0.12, 0.7], [523.25, 0.2, 0.7]],                                             // mi-dó descendo
    'alerta'    => [[880.0, 0.09, 0.9], [0, 0.06, 0], [880.0, 0.09, 0.9], [0, 0.06, 0], [880.0, 0.16, 0.9]], // três bipes
    'conquista' => [[523.25, 0.1, 0.7], [659.25, 0.1, 0.7], [783.99, 0.1, 0.7], [1046.5, 0.32, 0.9]],    // arpejo
];
foreach ($sounds as $name => $notes) {
    file_put_contents("{$out}/{$name}.wav", tone($notes, $rate));
    echo "{$name}.wav ", filesize("{$out}/{$name}.wav"), " bytes\n";
}
