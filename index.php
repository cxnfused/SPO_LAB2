<?php
declare(strict_types=1);

/**
 * Учебный статический анализатор PHP 8.2+.
 * CLI: количество одновременно вложенных управляющих конструкций.
 * CL: if, elseif, for, foreach, while, do, каждый case; исключены else/default.
 * n: количество вхождений операторов согласно перечню ниже.
 * Код пользователя НЕ выполняется.
 */
final class PhpMetricsAnalyzer
{
    private array $t = [];
    private int $p = 0;
    private int $maxDepth = 0;
    private array $counts = [];
    private array $ops = [];
    private array $branches = [];
    private int $functions = 0;
    private int $semicolonCount = 0;
    private int $decisions = 0;
    private int $caseCount = 0;
    private int $switchCount = 0;
    private int $catchCount = 0;
    private int $ternaries = 0;
    private array $warnings = [];

    private const CONTROL = [
        T_IF=>'if',T_ELSEIF=>'elseif',T_FOR=>'for',T_FOREACH=>'foreach',
        T_WHILE=>'while',T_DO=>'do',T_CASE=>'case'
    ];
    private const WORD_OPS = [
        T_RETURN=>'return',T_ECHO=>'echo',T_PRINT=>'print',T_BREAK=>'break',
        T_CONTINUE=>'continue',T_THROW=>'throw',T_YIELD=>'yield',
        T_INCLUDE=>'include',T_INCLUDE_ONCE=>'include_once',
        T_REQUIRE=>'require',T_REQUIRE_ONCE=>'require_once',
        T_NEW=>'new',T_CLONE=>'clone',T_UNSET=>'unset',T_ISSET=>'isset',
        T_EMPTY=>'empty',T_EXIT=>'exit',T_INSTANCEOF=>'instanceof',
        T_LOGICAL_AND=>'and',T_LOGICAL_OR=>'or',T_LOGICAL_XOR=>'xor',
        T_BOOLEAN_AND=>'&&',T_BOOLEAN_OR=>'||',T_COALESCE=>'??',
        T_IS_EQUAL=>'==',T_IS_NOT_EQUAL=>'!=',T_IS_IDENTICAL=>'===',
        T_IS_NOT_IDENTICAL=>'!==',T_IS_SMALLER_OR_EQUAL=>'<=',
        T_IS_GREATER_OR_EQUAL=>'>=',T_SPACESHIP=>'<=>',
        T_PLUS_EQUAL=>'+=',T_MINUS_EQUAL=>'-=',T_MUL_EQUAL=>'*=',
        T_DIV_EQUAL=>'/=',T_MOD_EQUAL=>'%=',T_CONCAT_EQUAL=>'.=',
        T_INC=>'++',T_DEC=>'--',T_POW=>'**',T_POW_EQUAL=>'**=',
        T_SL=>'<<',T_SR=>'>>',T_SL_EQUAL=>'<<=',T_SR_EQUAL=>'>>=',
        T_AND_EQUAL=>'&=',T_OR_EQUAL=>'|=',T_XOR_EQUAL=>'^=',
        T_OBJECT_OPERATOR=>'->',T_NULLSAFE_OBJECT_OPERATOR=>'?->',
        T_DOUBLE_ARROW=>'=>',T_COALESCE_EQUAL=>'??=',T_INT_CAST=>'(int)',
        T_STRING_CAST=>'(string)',T_BOOL_CAST=>'(bool)',T_DOUBLE_CAST=>'(float)',
        T_ARRAY_CAST=>'(array)',T_OBJECT_CAST=>'(object)',
    ];
    private const CHAR_OPS = ['=','+','-','*','/','%','.','<','>','!','&','|','^','~','@'];

    public function analyze(string $source): array
    {
        if (trim($source)==='') throw new InvalidArgumentException('Введите PHP-код.');
        // This catches many syntax errors without evaluating code.
        $raw = token_get_all($source, TOKEN_PARSE);
        $line = 1;
        foreach ($raw as $token) {
            $id = is_array($token) ? $token[0] : 0;
            $value = is_array($token) ? $token[1] : $token;
            $tokenLine = is_array($token) ? $token[2] : $line;
            $line = $tokenLine + substr_count($value, "\n");
            if (in_array($id,[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG,T_INLINE_HTML],true)) continue;
            $this->t[]=['id'=>$id,'value'=>$value,'line'=>$tokenLine];
        }
        $this->counts=array_fill_keys(array_values(self::CONTROL),0);
        $this->countOperators();
        $this->identifyLimitations();
        while (!$this->atEnd()) {
            $before=$this->p;
            $this->statement(0);
            if ($this->p === $before) $this->p++; // fail-safe for malformed/novel syntax
        }
        $cl=array_sum($this->counts);
        ksort($this->ops);
        $n=array_sum($this->ops);
        return [
            'counts'=>$this->counts,'absolute'=>$cl,'total'=>$n,
            'relative'=>$n ? $cl/$n : 0.0,'maxDepth'=>$this->maxDepth,
            'functions'=>$this->functions,'semicolons'=>$this->semicolonCount,
            'operatorCounts'=>$this->ops,'branchLines'=>$this->branches,
            'mccabeEstimate'=>null,
            'methodNote'=>'Маккейб требует графа потока управления; значение не подменяется формулой CL + функции + 1.',
            'ternaries'=>$this->ternaries,'switches'=>$this->switchCount,
            'warnings'=>array_values(array_unique($this->warnings)),
        ];
    }

    private function atEnd(): bool {return $this->p>=count($this->t);}
    private function id(int $offset=0): int {return $this->t[$this->p+$offset]['id']??-1;}
    private function val(int $offset=0): string {return $this->t[$this->p+$offset]['value']??'';}
    private function step(): void {$this->p++;}
    private function addOp(string $name): void {$this->ops[$name]=($this->ops[$name]??0)+1;}
    private function branch(string $type,int $depth): void {
        $this->counts[$type]++;
        $this->maxDepth=max($this->maxDepth,$depth);
        $this->branches[]=['line'=>$this->t[$this->p]['line'],'type'=>$type,'depth'=>$depth];
    }

    private function identifyLimitations(): void
    {
        foreach ($this->t as $i=>$token) {
            $id=$token['id'];$value=$token['value'];
            if ($id===T_MATCH) $this->warnings[]='Обнаружен match: ветви match не включены в CL/CLI. Для точных метрик используйте switch или AST-анализатор.';
            if ($id===T_FN) $this->warnings[]='Обнаружена стрелочная функция fn: вложенность выражений внутри неё может учитываться неполно.';
            if ($id===T_TRY || $id===T_CATCH) $this->warnings[]='Обнаружен try/catch: ветви исключений не учитываются в CL.';
            if ($value==='?') $this->warnings[]='Обнаружен тернарный оператор ?: : он не входит в CL и CLI согласно выбранной учебной методике.';
            if ($id===T_COALESCE) $this->warnings[]='Оператор ?? входит в n, но не увеличивает CL.';
        }
    }

    private function countOperators(): void
    {
        $n=count($this->t);
        for ($i=0;$i<$n;$i++) {
            $tk=$this->t[$i];$id=$tk['id'];$val=$tk['value'];
            if (isset(self::CONTROL[$id])) {
                // `while` closing do is counted as do, not an additional control.
                // This exception is handled via a recorded closing-while index below.
                $this->addOp(self::CONTROL[$id]);
            } elseif (isset(self::WORD_OPS[$id])) $this->addOp(self::WORD_OPS[$id]);
            elseif ($id===0 && in_array($val,self::CHAR_OPS,true)) $this->addOp($val);
            elseif ($id===0 && $val==='?') $this->addOp('?:');
            if ($val===';') $this->semicolonCount++;
            // Calls: distinguish definitions and static/method calls, allow namespace names.
            if (in_array($id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE],true)
                && (($this->t[$i+1]['value']??'')==='(')) {
                $prev=$this->t[$i-1]['id']??0;
                $before=$this->t[$i-1]['value']??'';
                if (!in_array($prev,[T_FUNCTION,T_FN,T_NEW],true)
                    && !in_array($before,['->','?->','::'],true)) $this->addOp('call()');
                elseif (in_array($before,['->','?->','::'],true)) $this->addOp('call()');
            }
            if ($id===T_FN || $id===T_FUNCTION) $this->functions++;
        }
    }

    /** Consume parenthesized condition even when it contains nested calls/arrays. */
    private function parentheses(): void {
        if ($this->val()!=='(') return;
        $depth=0;
        do {
            $v=$this->val();
            if ($v==='(') $depth++;
            elseif ($v===')') $depth--;
            $this->step();
        } while (!$this->atEnd() && $depth>0);
    }
    private function until(array $stops): void {
        $round=0;$square=0;$brace=0;
        while (!$this->atEnd()) {
            $v=$this->val();
            if ($round===0 && $square===0 && $brace===0 && in_array($v,$stops,true)) return;
            if ($v==='(') $round++; elseif ($v===')') $round=max(0,$round-1);
            elseif ($v==='[') $square++; elseif ($v===']') $square=max(0,$square-1);
            elseif ($v==='{') $brace++; elseif ($v==='}') {if($brace===0)return;$brace--;}
            $this->step();
        }
    }
    private function body(int $depth): void {
        if ($this->val()==='{') {
            $this->step();
            while (!$this->atEnd() && $this->val()!=='}') {
                $start=$this->p;$this->statement($depth);
                if ($this->p===$start) $this->step();
            }
            if ($this->val()==='}') $this->step();
        } else {
            $this->statement($depth);
        }
    }
    private function statement(int $depth): void {
        if ($this->atEnd()) return;
        $v=$this->val();$id=$this->id();
        if ($v===';' || $v==='}') {$this->step();return;}
        if ($v==='{') {$this->body($depth);return;}
        if ($id===T_IF) {
            $this->branch('if',$depth+1);$this->step();$this->parentheses();
            $alternative=$this->val()===':';
            if ($alternative) {$this->step();$this->alternative([T_ELSEIF,T_ELSE,T_ENDIF],$depth+1);}
            else $this->body($depth+1);
            while($this->id()===T_ELSEIF) {
                $this->branch('elseif',$depth+1);$this->step();$this->parentheses();
                if ($alternative && $this->val()===':') {$this->step();$this->alternative([T_ELSEIF,T_ELSE,T_ENDIF],$depth+1);}
                else $this->body($depth+1);
            }
            if($this->id()===T_ELSE) {
                $this->step();
                if($alternative && $this->val()===':') {$this->step();$this->alternative([T_ENDIF],$depth+1);}
                else $this->body($depth+1);
            }
            if ($alternative && $this->id()===T_ENDIF) {$this->step();if($this->val()===';')$this->step();}
            return;
        }
        if (in_array($id,[T_FOR,T_FOREACH,T_WHILE],true)) {
            $type=self::CONTROL[$id];$this->branch($type,$depth+1);$this->step();$this->parentheses();
            if ($this->val()===':') {
                $this->step();$ends=[T_FOR=>T_ENDFOR,T_FOREACH=>T_ENDFOREACH,T_WHILE=>T_ENDWHILE];
                $this->alternative([$ends[$id]],$depth+1);
                if ($this->id()===$ends[$id]) {$this->step();if($this->val()===';')$this->step();}
            } else $this->body($depth+1);
            return;
        }
        if ($id===T_DO) {
            $this->branch('do',$depth+1);$this->step();$this->body($depth+1);
            if ($this->id()===T_WHILE) {
                // Remove the extra lexeme `while` from the denominator as well.
                $this->ops['while']=max(0,($this->ops['while']??0)-1);
                $this->step();$this->parentheses();if($this->val()===';')$this->step();
            }
            return;
        }
        if ($id===T_SWITCH) {
            $this->switchCount++;$this->step();$this->parentheses();
            $alt=$this->val()===':';
            if($alt || $this->val()==='{') $this->step();
            while (!$this->atEnd() && ($alt?$this->id()!==T_ENDSWITCH:$this->val()!=='}')) {
                if($this->id()===T_CASE) {
                    $this->branch('case',$depth+1);$this->step();$this->until([':', ';']);
                    if(in_array($this->val(),[':', ';'],true))$this->step();
                } elseif($this->id()===T_DEFAULT) {
                    $this->step();$this->until([':', ';']);if(in_array($this->val(),[':', ';'],true))$this->step();
                } else {
                    $start=$this->p;$this->statement($depth+1);if($start===$this->p)$this->step();
                }
            }
            if(!$this->atEnd())$this->step();
            if($alt && $this->val()===';')$this->step();
            return;
        }
        if ($id===T_FUNCTION || $id===T_FN) {
            $isArrow=$id===T_FN;$this->step();
            // Scan signature, including attributes, types, return declarations.
            $this->until($isArrow?['=>',';']:['{',';']);
            if($this->val()==='{') $this->body(0); // separate function graph
            else {if($this->val()==='=>'){$this->step();$this->until([';',',']);}if($this->val()===';')$this->step();}
            return;
        }
        if ($id===T_CLASS || $id===T_TRAIT || $id===T_INTERFACE || $id===T_ENUM) {
            $this->step();$this->until(['{',';']);if($this->val()==='{')$this->body(0);else if($this->val()===';')$this->step();
            return;
        }
        if ($id===T_TRY || $id===T_CATCH || $id===T_FINALLY) {
            $this->step();if($id===T_CATCH)$this->parentheses();$this->body($depth+($id===T_CATCH?1:0));return;
        }
        if ($id===T_MATCH) {
            $this->step();$this->parentheses();
            if($this->val()==='{') {
                $this->step();
                while(!$this->atEnd() && $this->val()!=='}') {
                    $this->until(['=>','}']);
                    if($this->val()==='=>') { // each match arm is a branch, except default
                        $this->step();$this->until([',','}']);if($this->val()===',')$this->step();
                    }
                }
                if($this->val()==='}')$this->step();
            }
            if($this->val()===';')$this->step();return;
        }
        // General expressions, declarations, function calls, return etc.
        $this->until([';','}']);if($this->val()===';')$this->step();
    }
    private function alternative(array $endIds,int $depth): void {
        while(!$this->atEnd() && !in_array($this->id(),$endIds,true)) {
            $start=$this->p;$this->statement($depth);if($this->p===$start)$this->step();
        }
    }
}

function analyzePhp(string $source): array {return (new PhpMetricsAnalyzer())->analyze($source);}

$sampleFile = __DIR__ . '/sample.php';
$source = $_POST['source'] ?? file_get_contents($sampleFile);
$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = analyzePhp($source);
    } catch (ParseError $exception) {
        $error = 'Синтаксическая ошибка PHP: ' . $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'Ошибка анализа: ' . $exception->getMessage();
    }
} else {
    $result = analyzePhp($source);
}
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Метрика Джилба — PHP</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#172135;background:#f3f5f9}
*{box-sizing:border-box} body{margin:0} .page{max-width:1260px;margin:32px auto;padding:0 20px}
h1{margin:0 0 7px;font-size:28px} .muted{color:#596779;margin:0 0 22px;line-height:1.5}
.columns{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start}
.panel{background:white;border:1px solid #dee3ec;border-radius:14px;padding:20px;box-shadow:0 3px 14px #14213d08}
h2{font-size:18px;margin:0 0 12px}textarea{width:100%;height:580px;resize:vertical;display:block;padding:14px;border:1px solid #c8d0dc;border-radius:8px;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;tab-size:4;background:#fbfcfe}
.actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:14px}button{background:#234ba1;color:white;border:0;border-radius:8px;padding:11px 17px;font-weight:650;cursor:pointer}button:hover{background:#193a85}
a.reset{color:#234ba1;text-decoration:none}.metrics{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.metric{border-radius:9px;background:#f3f6fc;padding:14px}.metric strong{font-size:25px;display:block;margin:3px 0}.metric span{font-size:12px;color:#5d697c}
table{width:100%;border-collapse:collapse}td,th{padding:9px 7px;border-bottom:1px solid #e9edf3;text-align:left;font-size:14px}td:last-child,th:last-child{text-align:right}.error{padding:13px;background:#fff0f0;color:#8d2424;border-radius:8px}.note{font-size:13px;color:#596779;line-height:1.55;margin-top:17px}
@media(max-width:850px){.columns{grid-template-columns:1fr}textarea{height:370px}}
</style>
</head>
<body><main class="page">
<h1>Анализ сложности PHP-кода</h1>
<p class="muted">Лабораторная работа 2а · Метрики Джилба и оценка цикломатической сложности Маккейба</p>
<div class="columns">
<section class="panel"><h2>Исходный код</h2>
<form method="post"><textarea name="source" spellcheck="false" aria-label="PHP код"><?=e($source)?></textarea>
<div class="actions"><button type="submit">Рассчитать метрики</button><a class="reset" href="index.php">Восстановить пример</a></div></form></section>
<section class="panel"><h2>Результаты анализа</h2>
<?php if ($error !== ''): ?><div class="error"><?=e($error)?></div><?php endif; ?>
<?php if ($result !== null): ?>
<div class="metrics">
<div class="metric"><span>Абсолютная сложность CL</span><strong><?=$result['absolute']?></strong></div>
<div class="metric"><span>Относительная сложность cl</span><strong><?=number_format($result['relative'],4,'.','')?></strong></div>
<div class="metric"><span>Максимальная вложенность CLI</span><strong><?=$result['maxDepth']?></strong></div>
<div class="metric"><span>Всего операторов n</span><strong><?=$result['total']?></strong></div>
</div>
<?php if ($result['warnings']): ?>
<div class="error" style="background:#fff7e7;color:#664500"><strong>Ограничения для этого кода:</strong><ul><?php foreach($result['warnings'] as $warning): ?><li><?=e($warning)?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<h2>Детализация</h2>
<table><thead><tr><th>Тип управляющей конструкции</th><th>Количество</th></tr></thead><tbody>
<?php foreach ($result['counts'] as $name => $count): ?>
<tr><td><code><?=e($name)?></code></td><td><?=$count?></td></tr>
<?php endforeach; ?>
</tbody></table>
<h2 style="margin-top:20px">Операторы для знаменателя n</h2>
<table><thead><tr><th>Оператор</th><th>Количество</th></tr></thead><tbody>
<?php foreach ($result['operatorCounts'] as $name => $count): ?>
<tr><td><code><?=e($name)?></code></td><td><?=$count?></td></tr>
<?php endforeach; ?>
<tr><td><b>Всего n</b></td><td><b><?=$result['total']?></b></td></tr>
</tbody></table><h2 style="margin-top:20px">Дополнительные сведения</h2>
</tbody></table>
<p class="note"><b>Формула:</b> cl = CL / n = <?=$result['absolute']?> / <?=$result['total']?>.
Подсчёт n: вхождения управляющих, арифметических, логических и сравнительных операторов, присваиваний, вызовов и отдельных инструкций. Точки с запятой и скобки не входят в n. Для switch каждый case учитывается как условие; default не увеличивает CL. Для do...while вся конструкция считается одним циклом. Число V(G) не выводится без построения графа потока управления (CFG).</p>
<?php endif; ?>
<p class="note"><b>Границы реализации:</b> анализирует блочные конструкции с фигурными скобками, однострочные тела и альтернативный синтаксис if/for/foreach/while/switch. Сложные выражения match, тернарные операции, анонимные функции, исключения и нестандартные формы PHP требуют отдельного анализа; полноценный AST и CFG не строятся.</p>
</section></div></main></body></html>
