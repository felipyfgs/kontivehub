from __future__ import annotations

import base64
import json
import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from worker import (
    PORTAL_MANIFEST_VERSION,
    allowed_host,
    artifact,
    debt_from_cells,
    get_hcaptcha_rqdata,
    get_hcaptcha_sitekey,
    guide_equivalent,
    guide_type_from_cells,
    normalize_competence,
    parse_brl_amount,
    parse_proxy_url,
    payment_status_from_cells,
    preview_payload,
    resolve_challenge,
    response,
    select_profile_and_employer,
    solve_hcaptcha,
    wait_for_hcaptcha_sitekey,
)


class WorkerContractTest(unittest.TestCase):
    def test_allowlist_accepts_only_configured_suffixes(self) -> None:
        self.assertTrue(allowed_host("fgtsdigital.sistema.gov.br", [".gov.br"]))
        self.assertFalse(allowed_host("gov.br.attacker.example", [".gov.br"]))

    def test_pdf_artifact_is_digest_bound(self) -> None:
        document = b"%PDF-1.4\n%%EOF\n"
        result = artifact("guide.pdf", "application/pdf", document)
        self.assertEqual(document, base64.b64decode(result["content_base64"]))
        self.assertEqual(64, len(result["sha256"]))

    def test_public_response_has_no_implicit_secret_fields(self) -> None:
        result = response("HUMAN_CHALLENGE_REQUIRED", "HUMAN_CHALLENGE_REQUIRED", "human")
        self.assertIsNone(result["session"])
        self.assertNotIn("credential", result)

    def test_proxy_is_normalized_for_browser_and_provider(self) -> None:
        browser, provider = parse_proxy_url("http://worker:secret@127.0.0.1:8080")

        self.assertEqual("http://127.0.0.1:8080", browser["server"])
        self.assertEqual("worker", browser["username"])
        self.assertEqual("127.0.0.1", provider["host"])
        self.assertEqual("8080", provider["port"])

    def test_solver_allows_missing_optional_proxy(self) -> None:
        browser, provider = parse_proxy_url("")

        self.assertIsNone(browser)
        self.assertEqual({}, provider)

    def test_solver_rejects_malformed_optional_proxy(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "CAPTCHA_PROXY_INVALID"):
            parse_proxy_url("http://missing-port.example")

    def test_solver_reads_hcaptcha_context_from_iframe_fragment(self) -> None:
        src = (
            "https://newassets.hcaptcha.com/captcha/static/hcaptcha.html"
            "#frame=checkbox&sitekey=fragment-site-key&rqdata=fragment-rq-data"
        )
        element = SimpleNamespace(
            get_attribute=lambda name: src if name == "src" else None,
        )
        locator = SimpleNamespace(count=lambda: 1, first=element)
        page = SimpleNamespace(locator=Mock(return_value=locator), frames=[])

        self.assertEqual("fragment-site-key", get_hcaptcha_sitekey(page))
        self.assertEqual("fragment-rq-data", get_hcaptcha_rqdata(page))

    @patch("worker.get_hcaptcha_sitekey", side_effect=[None, None, "eventual-site-key"])
    def test_solver_waits_for_async_hcaptcha_context(self, _sitekey_mock: Mock) -> None:
        page = SimpleNamespace(wait_for_timeout=Mock())

        self.assertEqual("eventual-site-key", wait_for_hcaptcha_sitekey(page))
        self.assertEqual(2, page.wait_for_timeout.call_count)

    @patch("worker.nopecha_cookies", return_value=[])
    @patch("worker.get_hcaptcha_rqdata", return_value="rq-data")
    @patch("worker.get_hcaptcha_sitekey", return_value="site-key")
    @patch("worker.request_json")
    def test_solver_posts_real_context_and_polls_for_token(
        self,
        request_json_mock: Mock,
        _sitekey_mock: Mock,
        _rqdata_mock: Mock,
        _cookies_mock: Mock,
    ) -> None:
        captured: list[dict[str, object] | None] = []

        def fake_request(_url: str, payload: dict[str, object] | None, _timeout: int):
            captured.append(None if payload is None else dict(payload))
            return (200, {"data": "job-id"}) if len(captured) == 1 else (200, {"data": "single-use-token"})

        request_json_mock.side_effect = fake_request
        page = SimpleNamespace(
            url="https://sso.acesso.gov.br/login?client_id=fgts",
            evaluate=Mock(return_value="Browser UA"),
        )
        config = {
            "endpoint": "https://api.nopecha.com/token/",
            "api_key": "provider-secret",
            "timeout_seconds": 10,
            "poll_interval_milliseconds": 250,
        }
        proxy = {"scheme": "http", "host": "127.0.0.1", "port": "8080"}

        token = solve_hcaptcha(page, object(), config, proxy)

        self.assertEqual("single-use-token", token)
        submitted = captured[0]
        self.assertIsNotNone(submitted)
        assert submitted is not None
        self.assertEqual("hcaptcha", submitted["type"])
        self.assertEqual("site-key", submitted["sitekey"])
        self.assertEqual(page.url, submitted["url"])
        self.assertEqual("Browser UA", submitted["useragent"])
        self.assertEqual(proxy, submitted["proxy"])
        self.assertEqual({"rqdata": "rq-data"}, submitted["data"])
        self.assertIn("id=job-id", request_json_mock.call_args_list[1].args[0])

    @patch("worker.nopecha_cookies", return_value=[])
    @patch("worker.get_hcaptcha_rqdata", return_value=None)
    @patch("worker.get_hcaptcha_sitekey", return_value="site-key")
    @patch("worker.request_json")
    def test_solver_uses_external_token_api_without_proxy(
        self,
        request_json_mock: Mock,
        _sitekey_mock: Mock,
        _rqdata_mock: Mock,
        _cookies_mock: Mock,
    ) -> None:
        request_json_mock.side_effect = [
            (200, {"data": "job-id"}),
            (200, {"data": "external-token"}),
        ]
        page = SimpleNamespace(
            url="https://sso.acesso.gov.br/login?client_id=fgts",
            evaluate=Mock(return_value="Browser UA"),
        )

        token = solve_hcaptcha(page, object(), {
            "endpoint": "https://api.nopecha.com/token/",
            "api_key": "provider-secret",
            "timeout_seconds": 10,
            "poll_interval_milliseconds": 250,
        }, {})

        self.assertEqual("external-token", token)
        submitted = request_json_mock.call_args_list[0].args[1]
        self.assertNotIn("proxy", submitted)

    def test_portal_manifest_parsers_keep_unknown_payment_honest(self) -> None:
        self.assertEqual("2026-07", normalize_competence("07/2026"))
        self.assertEqual(184250, parse_brl_amount("R$ 1.842,50"))
        self.assertEqual("UNKNOWN", payment_status_from_cells(["Guia", "Processada"]))
        self.assertEqual("NOT_CONFIRMED", payment_status_from_cells(["Guia", "Não pago"]))
        self.assertEqual("NOT_CONFIRMED", payment_status_from_cells(["Guia", "Em aberto"]))
        self.assertEqual("CONFIRMED", payment_status_from_cells(["Guia", "Pago"]))
        self.assertEqual("PARTIAL", payment_status_from_cells(["Pagamento parcial"]))
        self.assertEqual("TERMINATION", guide_type_from_cells(["Guia rescisória"]))
        debt = debt_from_cells(["12.345.678/0001-90", "07/2026", "R$ 1.842,50", "Em aberto"])
        self.assertTrue(debt["identifier"].startswith("DEBT-HASH:"))
        self.assertNotIn("12345678000190", json.dumps(debt))
        self.assertEqual("OPEN", debt["status"])

    def test_parameterized_preview_exposes_counts_and_bound_fingerprint_only(self) -> None:
        private_employee = "12345678901"
        private_debit = "debit-secret-id"
        preview = preview_payload(
            {
                "competence_period_key": "2026-07",
                "guide_type": "PARAMETERIZED",
                "employee_ids": [private_employee],
                "debit_ids": [private_debit],
                "_selection_fingerprint": "a" * 64,
            }
        )
        encoded = json.dumps(preview)
        self.assertEqual(PORTAL_MANIFEST_VERSION, preview["manifest_version"])
        self.assertEqual(1, preview["employee_count"])
        self.assertEqual("a" * 64, preview["selection_fingerprint"])
        self.assertNotIn(private_employee, encoded)
        self.assertNotIn(private_debit, encoded)

    def test_reuse_requires_exact_competence_type_amount_and_safe_selection(self) -> None:
        guide = {
            "competence": "07/2026",
            "guide_type": "MONTHLY",
            "amount_cents": 184250,
        }
        parameters = {
            "competence_period_key": "2026-07",
            "guide_type": "MONTHLY",
            "amount_cents": 184250,
        }
        self.assertTrue(guide_equivalent(guide, parameters))
        self.assertFalse(guide_equivalent(guide, {**parameters, "amount_cents": 1}))
        self.assertFalse(guide_equivalent(guide, {**parameters, "debit_ids": ["private"]}))

    def test_direct_client_keeps_own_profile_and_procurator_requires_confirmed_employer(self) -> None:
        page = Mock()
        select_profile_and_employer(
            page,
            {"credential_source": "CLIENT", "profile_type": "EMPREGADOR", "target_identifier": "123"},
        )
        page.locator.assert_not_called()

        page.locator.return_value.inner_text.return_value = "Área autenticada sem empregador selecionado"
        with patch("worker.click_first_text", side_effect=[True, False]):
            with self.assertRaisesRegex(RuntimeError, "REPRESENTATION_NOT_CONFIRMED"):
                select_profile_and_employer(
                    page,
                    {
                        "credential_source": "OFFICE",
                        "profile_type": "PROCURADOR_PJ",
                        "target_identifier": "12.345.678/0001-90",
                    },
                )

    @patch("worker.inject_hcaptcha_token", return_value=False)
    @patch("worker.solve_hcaptcha", return_value="single-use-token")
    def test_rejected_token_never_becomes_session(self, _solve: Mock, _inject: Mock) -> None:
        with self.assertRaisesRegex(RuntimeError, "CAPTCHA_TOKEN_REJECTED"):
            resolve_challenge(Mock(), Mock(), {"driver": "nopecha", "max_attempts": 1}, {})


if __name__ == "__main__":
    unittest.main()
