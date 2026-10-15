<?php
// Анализ результатов контрольной работы.
function gradeName(float $avg): string {
    if ($avg >= 85) {
        return 'Отлично';
    } elseif ($avg >= 60) {
        return 'Хорошо';
    } else {
        return 'Нужно повторить';
    }
}

$scores = [90, 75, 40, 85];
$sum = 0;
$passed = 0;
$excellent = 0;
foreach ($scores as $score) {
    $sum += $score;
    if ($score >= 60) {
        $passed++;
        if ($score >= 70) {
            if ($score >= 80) {
                if ($score >= 90) {
                    $excellent++;
                }
            }
        }
    }
}
$average = $sum / count($scores);

for ($i = 0; $i < count($scores); $i++) {
    if ($scores[$i] < 60) {
        $scores[$i] += 5;
    }
}
$i = 0;
while ($i < count($scores)) {
    $i++;
}
do {
    $average = round($average, 2);
} while ($average < 0);

switch (gradeName($average)) {
    case 'Отлично':
        $message = 'Высокий результат';
        break;
    case 'Хорошо':
        $message = 'Хороший результат';
        break;
    default:
        $message = 'Требуется подготовка';
}
echo 'Средний балл: ' . $average . "\n";
echo 'Сдали: ' . $passed . "\n";
echo 'Отличных оценок: ' . $excellent . "\n";
echo $message . "\n";
