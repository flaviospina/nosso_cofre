<?php
// tests/HelpersTest.php
declare(strict_types=1);

function test_money_format_pt_br(): void
{
    assert_same("R$\u{a0}1.234,56", money('1234.56'));
    assert_same("-R$\u{a0}0,50", money(-0.5));
    assert_same('1.000.000,00', money(1000000, false));
}

function test_date_helpers(): void
{
    assert_same('31/12/2026', date_br('2026-12-31'));
    assert_same('', date_br(null));
    assert_same('março', month_name(3));
    assert_same('FS', initials('Flávio Spina'));
    assert_same('P', initials('Priscila'));
}

function test_escape_helper(): void
{
    assert_same('&lt;b&gt;&quot;x&quot; &#039;y&#039;', e('<b>"x" \'y\''));
    assert_same('', e(null));
}
