<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Contract: the web login page's "Don't have an account?" link text must
 * follow the installation's configured onboarding mode.
 *
 *   BbsConfig::shouldRequireRegistrationApproval() === true
 *       -> ui.login.request_access      ("Request Access")
 *   BbsConfig::shouldRequireRegistrationApproval() === false
 *       -> ui.register.create_account   ("Create Account")
 *
 * This is a generic BinkTermPHP fix: approval-gated installs keep the
 * "Request Access" wording; auto-approve installs stop implying a review
 * step. Both login presentation paths (standard + ansi_prompt) share the
 * single computed key. No new i18n key is introduced, and registration
 * behaviour, register.twig, and the /register target are untouched.
 *
 * Style mirrors RememberMeContractTest: assert against source so the
 * route/template contract is protected without a database, a real user,
 * or edits to the operator-owned config/bbs.json. One additional test
 * renders the exact template expression in isolation to prove both
 * branches choose the right key.
 */
final class LoginRegisterLinkContractTest extends TestCase
{
    private string $loginTemplate;
    private string $loginTemplateFlat;
    private string $webRoutes;
    private string $enCommon;
    private string $registerTemplate;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loginTemplate     = (string)file_get_contents($root . '/templates/login.twig');
        $this->loginTemplateFlat = (string)preg_replace('/\s+/', ' ', $this->loginTemplate);
        $this->webRoutes         = (string)file_get_contents($root . '/routes/web-routes.php');
        $this->enCommon          = (string)file_get_contents($root . '/config/i18n/en/common.php');
        $this->registerTemplate  = (string)file_get_contents($root . '/templates/register.twig');
    }

    private function loginGetRoute(): string
    {
        return $this->between(
            $this->webRoutes,
            "SimpleRouter::get('/login'",
            "SimpleRouter::get('/register'"
        );
    }

    public function testLoginRouteExposesApprovalFlagFromConfigPrimitive(): void
    {
        $route = $this->loginGetRoute();

        self::assertStringContainsString("renderResponse('login.twig'", $route);
        self::assertStringContainsString(
            "'registration_requires_approval' => \\BinktermPHP\\BbsConfig::shouldRequireRegistrationApproval(),",
            $route
        );
    }

    public function testTemplateComputesRegisterLinkKeyFromApprovalFlag(): void
    {
        self::assertStringContainsString(
            "{% set register_link_key = (registration_requires_approval ?? true) "
                . "? 'ui.login.request_access' : 'ui.register.create_account' %}",
            $this->loginTemplateFlat
        );
    }

    public function testBothLoginPresentationPathsUseTheComputedKey(): void
    {
        // ansi_prompt path
        $ansiLinks = $this->between(
            $this->loginTemplate,
            '<div class="login-ansi-links">',
            '</div>'
        );
        self::assertStringContainsString("t(register_link_key, {}, 'common')", $ansiLinks);

        // standard path (guarded by display_mode != 'ansi_prompt')
        $standardLinks = $this->between(
            $this->loginTemplate,
            "{% if login_screen.display_mode|default('standard') != 'ansi_prompt' %}",
            '{% endif %}'
        );
        self::assertStringContainsString("t(register_link_key, {}, 'common')", $standardLinks);

        // Exactly the two register links, no stragglers.
        self::assertSame(
            2,
            substr_count($this->loginTemplate, "t(register_link_key, {}, 'common')")
        );
    }

    public function testNoHardCodedRequestAccessRemainsOnTheRegisterLinks(): void
    {
        self::assertStringNotContainsString(
            "<a href=\"/register\">{{ t('ui.login.request_access', {}, 'common') }}</a>",
            $this->loginTemplate
        );
        self::assertStringNotContainsString(
            "class=\"text-decoration-none\">{{ t('ui.login.request_access', {}, 'common') }}</a>",
            $this->loginTemplate
        );
    }

    public function testRegisterHrefIsUnchanged(): void
    {
        self::assertSame(2, substr_count($this->loginTemplate, '<a href="/register"'));
    }

    public function testNoNewI18nKeyIsIntroduced(): void
    {
        // Both referenced keys already exist in the base English catalog...
        self::assertStringContainsString("'ui.login.request_access' => ", $this->enCommon);
        self::assertStringContainsString("'ui.register.create_account' => ", $this->enCommon);
        // ...and no login-scoped "create account" key was invented.
        self::assertStringNotContainsString("'ui.login.create_account'", $this->enCommon);
        self::assertStringNotContainsString("ui.login.create_account", $this->loginTemplate);
    }

    public function testRegisterTemplateAndItsApprovalCopyAreUntouched(): void
    {
        self::assertStringContainsString(
            '{% if registration_requires_approval %}',
            $this->registerTemplate
        );
        self::assertStringNotContainsString('register_link_key', $this->registerTemplate);
    }

    public function testRenderedBranchChoosesCorrectKeyForBothApprovalStates(): void
    {
        // The exact expression used at the top of login.twig's content block.
        $expr = <<<'TWIG'
        {%- set register_link_key = (registration_requires_approval ?? true)
            ? 'ui.login.request_access'
            : 'ui.register.create_account' -%}
        {{- t(register_link_key, {}, 'common') -}}
        TWIG;

        $twig = new Environment(new ArrayLoader(['expr' => $expr]));
        // Stub t() to echo its key, so the assertion is purely on branch choice.
        $twig->addFunction(new TwigFunction(
            't',
            static fn(string $key, array $params = [], ...$rest): string => $key
        ));

        self::assertSame(
            'ui.login.request_access',
            $twig->render('expr', ['registration_requires_approval' => true]),
            'approval required -> Request Access'
        );
        self::assertSame(
            'ui.register.create_account',
            $twig->render('expr', ['registration_requires_approval' => false]),
            'approval not required -> Create Account'
        );
        // Legacy safety: a template rendered without the variable falls back
        // to the gated wording (matches BbsConfig::shouldRequireRegistrationApproval()).
        self::assertSame(
            'ui.login.request_access',
            $twig->render('expr', []),
            'missing flag -> gated default'
        );
    }

    private function between(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start, "Missing start marker: {$startNeedle}");
        $end = strpos($source, $endNeedle, $start);
        self::assertNotFalse($end, "Missing end marker: {$endNeedle}");

        return substr($source, $start, ($end - $start) + strlen($endNeedle));
    }
}
