#!/usr/bin/env python3
"""Worker isolado do FGTS Digital.

Contrato: um JSON no stdin e um JSON no stdout. Não registra PFX, senha, cookies,
chave/token de CAPTCHA, proxy, HTML fiscal nem respostas brutas.
"""

from __future__ import annotations

import base64
import hashlib
import json
import re
import sys
import time
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlparse
from urllib.request import Request, urlopen

CONTRACT_VERSION = 1
MAX_INPUT_BYTES = 16 * 1024 * 1024
MAX_ARTIFACT_BYTES = 25 * 1024 * 1024
PORTAL_MANIFEST_VERSION = "2026-07-22.1"
PORTAL_MANIFEST = {
    "authenticated_markers": [
        r"sair",
        r"trocar\s+perfil",
        r"selecionar\s+perfil",
        r"gest[aã]o\s+de\s+guias",
        r"d[eé]bitos\s+do\s+empregador",
    ],
    "guide_navigation": [r"gest[aã]o de guias", r"consultar guias", r"guias emitidas"],
    "debt_navigation": [r"d[eé]bitos do empregador", r"consultar d[eé]bitos", r"d[eé]bitos"],
    "emission_navigation": [r"emitir guia", r"guia r[aá]pida", r"guia parametrizada"],
    "emission_confirmation": [r"^emitir$", r"^gerar guia$", r"^confirmar emiss[aã]o$"],
    "profile_switch": [r"trocar\s+perfil", r"selecionar\s+perfil"],
    "procurator_profile": [r"procurador(?:\s+pj)?", r"representante"],
    "employer_inputs": [
        'input[name*="empregador" i]',
        'input[placeholder*="CNPJ" i]',
        'input[aria-label*="CNPJ" i]',
    ],
    "competence_inputs": [
        'input[name*="competencia" i]',
        'input[placeholder*="competência" i]',
        'input[aria-label*="competência" i]',
    ],
    "due_date_inputs": [
        'input[name*="vencimento" i]',
        'input[aria-label*="vencimento" i]',
    ],
    "amount_inputs": [
        'input[name*="valor" i]',
        'input[aria-label*="valor" i]',
    ],
    "guide_rows": "table tbody tr",
}
GUIDE_OPERATIONS = {
    "READINESS",
    "AUTHENTICATE",
    "QUERY_GUIDES",
    "QUERY_PAYMENT",
    "PREVIEW",
    "EMIT_GUIDE",
    "DOWNLOAD_GUIDE",
}


def response(
    status: str,
    code: str,
    message: str,
    data: dict[str, Any] | None = None,
    artifacts: list[dict[str, Any]] | None = None,
    session: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "contract_version": CONTRACT_VERSION,
        "status": status,
        "code": code,
        "message": message,
        "data": data or {},
        "artifacts": artifacts or [],
        "session": session,
    }


def artifact(name: str, content_type: str, content: bytes) -> dict[str, str]:
    if content_type != "application/pdf" or not content.startswith(b"%PDF-"):
        raise ValueError("INVALID_PDF")
    if len(content) < 8 or len(content) > MAX_ARTIFACT_BYTES:
        raise ValueError("INVALID_PDF_SIZE")
    return {
        "name": name,
        "content_type": content_type,
        "content_base64": base64.b64encode(content).decode("ascii"),
        "sha256": hashlib.sha256(content).hexdigest(),
    }


def normalized_digits(value: Any) -> str:
    return re.sub(r"\D+", "", str(value or ""))


def normalize_competence(value: Any) -> str | None:
    text = str(value or "").strip()
    match = re.fullmatch(r"(\d{2})/(\d{4})", text)
    if match:
        return f"{match.group(2)}-{match.group(1)}"
    return text if re.fullmatch(r"\d{4}-\d{2}", text) else None


def parse_brl_amount(value: Any) -> int | None:
    text = re.sub(r"[^\d,.-]", "", str(value or "")).strip()
    if not text:
        return None
    if "," in text:
        text = text.replace(".", "").replace(",", ".")
    try:
        amount = Decimal(text)
    except InvalidOperation:
        return None
    if amount < 0:
        return None
    return int((amount * 100).quantize(Decimal("1")))


def payment_status_from_cells(cells: list[str]) -> str:
    text = " ".join(cells)
    if re.search(r"parcial(?:mente)?|pagamento parcial", text, re.I):
        return "PARTIAL"
    if re.search(r"n[aã]o pago|em aberto|pendente|vencido", text, re.I):
        return "NOT_CONFIRMED"
    if re.search(r"pago|quitado|recolhido", text, re.I):
        return "CONFIRMED"
    return "UNKNOWN"


def guide_type_from_cells(cells: list[str]) -> str:
    text = " ".join(cells)
    if re.search(r"rescis", text, re.I):
        return "TERMINATION"
    if re.search(r"consign", text, re.I):
        return "CONSIGNMENT"
    if re.search(r"mista|mixed", text, re.I):
        return "MIXED"
    if re.search(r"parametr", text, re.I):
        return "PARAMETERIZED"
    return "MONTHLY"


def safe_row_identifier(cells: list[str], prefix: str) -> str:
    identifier = next(
        (
            value
            for value in cells
            if re.search(r"\d{6,}", value)
            and len(normalized_digits(value)) not in {11, 14}
        ),
        None,
    )
    if identifier is not None:
        return identifier
    identity = "|".join(value.strip().lower() for value in cells)
    return prefix + hashlib.sha256(identity.encode("utf-8")).hexdigest()


def debt_from_cells(cells: list[str]) -> dict[str, Any]:
    competence = next((value for value in cells if re.fullmatch(r"\d{2}/\d{4}|\d{4}-\d{2}", value)), None)
    amount_text = next((value for value in cells if "R$" in value), None)
    due = next((value for value in cells if re.fullmatch(r"\d{2}/\d{2}/\d{4}", value)), None)
    text = " ".join(cells)
    status = "CLOSED" if re.search(r"quitado|recolhido|encerrado", text, re.I) else (
        "OPEN" if re.search(r"aberto|pendente|vencido", text, re.I) else "UNKNOWN"
    )
    return {
        "identifier": safe_row_identifier(cells, "DEBT-HASH:"),
        "competence": normalize_competence(competence),
        "amount_cents": parse_brl_amount(amount_text),
        "due_date": due,
        "status": status,
        "checked_at": datetime.now(timezone.utc).isoformat(),
        "manifest_version": PORTAL_MANIFEST_VERSION,
    }


def selection_fingerprint(parameters: dict[str, Any]) -> str:
    supplied = str(parameters.get("_selection_fingerprint") or "")
    if re.fullmatch(r"[a-f0-9]{64}", supplied):
        return supplied
    public = {
        key: parameters.get(key)
        for key in (
            "competence_period_key",
            "guide_type",
            "amount_cents",
            "due_at",
            "establishment_id",
            "include_monthly",
            "include_termination",
            "include_consignment",
        )
        if key in parameters
    }
    public["employee_count"] = len(parameters.get("employee_ids") or [])
    public["debit_count"] = len(parameters.get("debit_ids") or [])
    return hashlib.sha256(json.dumps(public, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def preview_payload(parameters: dict[str, Any]) -> dict[str, Any]:
    return {
        "competence_period_key": normalize_competence(parameters.get("competence_period_key")),
        "guide_type": str(parameters.get("guide_type") or "MONTHLY"),
        "amount_cents": parameters.get("amount_cents"),
        "due_at": parameters.get("due_at"),
        "employee_count": len(parameters.get("employee_ids") or []),
        "debit_count": len(parameters.get("debit_ids") or []),
        "selection_fingerprint": selection_fingerprint(parameters),
        "manifest_version": PORTAL_MANIFEST_VERSION,
        "remote_effect": False,
    }


def guide_equivalent(guide: dict[str, Any], parameters: dict[str, Any]) -> bool:
    if (parameters.get("employee_ids") or parameters.get("debit_ids")) and not guide.get("selection_fingerprint"):
        return False
    expected_competence = normalize_competence(parameters.get("competence_period_key"))
    if expected_competence and normalize_competence(guide.get("competence")) != expected_competence:
        return False
    expected_type = str(parameters.get("guide_type") or "MONTHLY")
    if str(guide.get("guide_type") or "MONTHLY") != expected_type:
        return False
    expected_amount = parameters.get("amount_cents")
    if expected_amount is not None and int(guide.get("amount_cents") or -1) != int(expected_amount):
        return False
    expected_fingerprint = selection_fingerprint(parameters)
    if guide.get("selection_fingerprint") and guide["selection_fingerprint"] != expected_fingerprint:
        return False
    return True


def allowed_host(host: str | None, suffixes: list[str]) -> bool:
    if not host:
        return False
    host = host.lower().rstrip(".")
    return any(host == suffix.lstrip(".") or host.endswith(suffix) for suffix in suffixes)


def challenge_present(page: Any) -> bool:
    selectors = [
        'iframe[src*="hcaptcha"]',
        'iframe[src*="recaptcha"]',
        '[data-sitekey]',
        'textarea[name="h-captcha-response"]',
    ]
    if any(page.locator(selector).count() > 0 for selector in selectors):
        return True
    try:
        body = page.locator("body").inner_text(timeout=3_000)
    except Exception:
        return False
    return bool(re.search(r"captcha|verifique que (?:voc[eê]|voce) [eé] humano", body, re.I))


def url_parameters(url: str) -> dict[str, list[str]]:
    parsed = urlparse(url)
    parameters = parse_qs(parsed.fragment)
    parameters.update(parse_qs(parsed.query))
    return parameters


def get_hcaptcha_sitekey(page: Any) -> str | None:
    """Extract hCaptcha sitekey from the page."""
    selectors = [
        '[data-sitekey]',
        'iframe[src*="hcaptcha"]',
    ]
    for selector in selectors:
        elements = page.locator(selector)
        if elements.count() > 0:
            sitekey = elements.first.get_attribute("data-sitekey")
            if sitekey:
                return sitekey
            # Try to extract from iframe src
            src = elements.first.get_attribute("src")
            if src:
                query = url_parameters(src)
                for name in ("sitekey", "siteKey", "k"):
                    values = query.get(name)
                    if values and values[0]:
                        return values[0]
    for frame in page.frames:
        query = url_parameters(frame.url)
        for name in ("sitekey", "siteKey", "k"):
            values = query.get(name)
            if values and values[0]:
                return values[0]
    return None


def wait_for_hcaptcha_sitekey(page: Any, timeout_ms: int = 10_000) -> str | None:
    deadline = time.monotonic() + (timeout_ms / 1_000)
    while time.monotonic() < deadline:
        sitekey = get_hcaptcha_sitekey(page)
        if sitekey:
            return sitekey
        page.wait_for_timeout(250)
    return None


def get_hcaptcha_rqdata(page: Any) -> str | None:
    for selector in ("[data-rqdata]", 'iframe[src*="hcaptcha"]'):
        elements = page.locator(selector)
        if elements.count() == 0:
            continue
        value = elements.first.get_attribute("data-rqdata")
        if value:
            return value
        src = elements.first.get_attribute("src")
        values = url_parameters(src or "").get("rqdata")
        if values and values[0]:
            return values[0]
    for frame in page.frames:
        values = url_parameters(frame.url).get("rqdata")
        if values and values[0]:
            return values[0]
    return None


def parse_proxy_url(proxy_url: str) -> tuple[dict[str, str] | None, dict[str, str]]:
    if not proxy_url.strip():
        return None, {}
    parsed = urlparse(proxy_url)
    if (
        parsed.scheme not in {"http", "https", "socks4", "socks5"}
        or not parsed.hostname
        or parsed.port is None
    ):
        raise RuntimeError("CAPTCHA_PROXY_INVALID")
    browser_proxy = {"server": f"{parsed.scheme}://{parsed.hostname}:{parsed.port}"}
    provider_proxy = {
        "scheme": parsed.scheme,
        "host": parsed.hostname,
        "port": str(parsed.port),
    }
    if parsed.username is not None or parsed.password is not None:
        if parsed.username is None or parsed.password is None:
            raise RuntimeError("CAPTCHA_PROXY_INVALID")
        browser_proxy["username"] = parsed.username
        browser_proxy["password"] = parsed.password
        provider_proxy["username"] = parsed.username
        provider_proxy["password"] = parsed.password
    return browser_proxy, provider_proxy


def nopecha_cookies(context: Any, page_url: str) -> list[dict[str, Any]]:
    cookies: list[dict[str, Any]] = []
    for item in context.cookies([page_url]):
        expires = float(item.get("expires", -1))
        cookies.append(
            {
                "name": str(item.get("name") or ""),
                "value": str(item.get("value") or ""),
                "domain": str(item.get("domain") or ""),
                "path": str(item.get("path") or "/"),
                "hostOnly": not str(item.get("domain") or "").startswith("."),
                "httpOnly": bool(item.get("httpOnly", False)),
                "secure": bool(item.get("secure", False)),
                "session": expires <= 0,
                "expirationDate": 0 if expires <= 0 else expires,
            }
        )
    return cookies


def request_json(url: str, payload: dict[str, Any] | None, timeout: int) -> tuple[int, dict[str, Any]]:
    body = None if payload is None else json.dumps(payload, separators=(",", ":")).encode("utf-8")
    request = Request(url, data=body, method="GET" if payload is None else "POST")
    request.add_header("Accept", "application/json")
    if body is not None:
        request.add_header("Content-Type", "application/json")
    try:
        with urlopen(request, timeout=timeout) as result:
            decoded = json.loads(result.read(1_048_577))
            return int(result.status), decoded if isinstance(decoded, dict) else {}
    except HTTPError as error:
        return int(error.code), {}
    except (URLError, TimeoutError, ValueError, json.JSONDecodeError):
        return 0, {}


def solve_hcaptcha(page: Any, context: Any, config: dict[str, Any], provider_proxy: dict[str, str]) -> str:
    sitekey = wait_for_hcaptcha_sitekey(page)
    if not sitekey:
        raise RuntimeError("CAPTCHA_CONTEXT_MISSING")
    endpoint = str(config.get("endpoint") or "")
    if endpoint != "https://api.nopecha.com/token/":
        raise RuntimeError("CAPTCHA_ENDPOINT_NOT_ALLOWED")
    api_key = str(config.get("api_key") or "")
    if not api_key:
        raise RuntimeError("CAPTCHA_API_KEY_MISSING")

    page_url = page.url
    useragent = str(page.evaluate("navigator.userAgent"))
    payload: dict[str, Any] = {
        "key": api_key,
        "type": "hcaptcha",
        "sitekey": sitekey,
        "url": page_url,
        "cookie": nopecha_cookies(context, page_url),
        "useragent": useragent,
    }
    if provider_proxy:
        payload["proxy"] = provider_proxy
    rqdata = get_hcaptcha_rqdata(page)
    if rqdata:
        payload["data"] = {"rqdata": rqdata}

    timeout = max(10, min(int(config.get("timeout_seconds") or 180), 300))
    status, submitted = request_json(endpoint, payload, timeout)
    payload.clear()
    job_id = submitted.get("data")
    if status < 200 or status >= 300 or not isinstance(job_id, str) or not job_id:
        raise RuntimeError("CAPTCHA_PROVIDER_SUBMIT_FAILED")

    poll_ms = max(250, min(int(config.get("poll_interval_milliseconds") or 1_000), 10_000))
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        query = urlencode({"key": api_key, "id": job_id})
        status, solved = request_json(f"{endpoint}?{query}", None, min(timeout, 15))
        token = solved.get("data")
        if 200 <= status < 300 and isinstance(token, str) and token:
            return token
        if status not in {0, 409, 429, 500, 502, 503, 504}:
            raise RuntimeError("CAPTCHA_PROVIDER_RESULT_FAILED")
        time.sleep(poll_ms / 1_000)
    raise RuntimeError("CAPTCHA_PROVIDER_TIMEOUT")


def inject_hcaptcha_token(page: Any, token: str) -> bool:
    """Apply a single-use token and invoke the page callback/form in-place."""
    try:
        return bool(page.evaluate("""(token) => {
            let applied = false;
            for (const selector of [
                'textarea[name="h-captcha-response"]',
                'textarea[name="g-recaptcha-response"]',
                'input[name="h-captcha-response"]'
            ]) {
                for (const field of document.querySelectorAll(selector)) {
                    const descriptor = Object.getOwnPropertyDescriptor(
                        field instanceof HTMLTextAreaElement
                            ? HTMLTextAreaElement.prototype
                            : HTMLInputElement.prototype,
                        'value'
                    );
                    if (descriptor?.set) descriptor.set.call(field, token);
                    else field.value = token;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    applied = true;
                }
            }
            for (const widget of document.querySelectorAll('[data-callback]')) {
                const path = (widget.getAttribute('data-callback') || '').split('.').filter(Boolean);
                let callback = window;
                for (const part of path) callback = callback?.[part];
                if (typeof callback === 'function') {
                    callback(token);
                    applied = true;
                }
            }
            const field = document.querySelector(
                'textarea[name="h-captcha-response"],textarea[name="g-recaptcha-response"],input[name="h-captcha-response"]'
            );
            const form = field?.closest('form');
            if (form) {
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
                applied = true;
            }
            return applied;
        }""", token))
    except Exception:
        return False


def resolve_challenge(page: Any, context: Any, config: dict[str, Any], provider_proxy: dict[str, str]) -> None:
    if str(config.get("driver") or "disabled") != "nopecha":
        raise RuntimeError("HUMAN_CHALLENGE_REQUIRED")
    attempts = max(1, min(int(config.get("max_attempts") or 1), 2))
    for _ in range(attempts):
        token = solve_hcaptcha(page, context, config, provider_proxy)
        try:
            if not inject_hcaptcha_token(page, token):
                raise RuntimeError("CAPTCHA_TOKEN_REJECTED")
            page.wait_for_timeout(3_000)
            try:
                page.wait_for_load_state("domcontentloaded", timeout=10_000)
            except Exception:
                pass
            if not challenge_present(page):
                return
        finally:
            token = "\0" * len(token)
    raise RuntimeError("CAPTCHA_TOKEN_REJECTED")


def click_first_text(page: Any, patterns: list[str], timeout: int = 8_000) -> bool:
    for pattern in patterns:
        locator = page.get_by_text(re.compile(pattern, re.I)).first
        if locator.count() > 0:
            locator.click(timeout=timeout)
            return True
    return False


def fill_first(page: Any, selectors: list[str], value: str) -> bool:
    for selector in selectors:
        locator = page.locator(selector).first
        if locator.count() > 0:
            locator.fill(value)
            return True
    return False


def select_profile_and_employer(page: Any, subject: dict[str, Any]) -> None:
    source = str(subject.get("credential_source") or "CLIENT")
    profile_type = str(subject.get("profile_type") or "EMPREGADOR")
    if source != "OFFICE" and profile_type not in {"PROCURADOR", "PROCURADOR_PJ"}:
        return

    target = normalized_digits(subject.get("target_identifier"))
    if len(target) != 14:
        raise RuntimeError("REPRESENTATION_NOT_CONFIRMED")
    suffix = target[-6:]
    try:
        if suffix in normalized_digits(page.locator("body").inner_text(timeout=3_000)):
            return
    except Exception:
        pass
    if not click_first_text(page, PORTAL_MANIFEST["profile_switch"]):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")
    if not click_first_text(page, PORTAL_MANIFEST["procurator_profile"]):
        raise RuntimeError("REPRESENTATION_NOT_CONFIRMED")
    if not fill_first(page, PORTAL_MANIFEST["employer_inputs"], target):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    # O portal pode exibir CNPJ formatado ou mascarado; a confirmação usa apenas
    # o sufixo e nunca devolve o identificador no envelope de saída.
    candidate = page.get_by_text(re.compile(re.escape(suffix))).first
    if candidate.count() == 0:
        raise RuntimeError("REPRESENTATION_NOT_CONFIRMED")
    candidate.click()
    page.wait_for_load_state("domcontentloaded")
    try:
        body_digits = normalized_digits(page.locator("body").inner_text(timeout=3_000))
    except Exception:
        raise RuntimeError("REPRESENTATION_NOT_CONFIRMED")
    if suffix not in body_digits:
        raise RuntimeError("REPRESENTATION_NOT_CONFIRMED")


def open_emission_form(page: Any, app_url: str, parameters: dict[str, Any]) -> None:
    page.goto(app_url, wait_until="domcontentloaded")
    if challenge_present(page):
        raise RuntimeError("HUMAN_CHALLENGE_REQUIRED")
    if not click_first_text(page, PORTAL_MANIFEST["emission_navigation"]):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    competence = normalize_competence(parameters.get("competence_period_key"))
    if competence and not fill_first(page, PORTAL_MANIFEST["competence_inputs"], competence):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")
    due_at = str(parameters.get("due_at") or "")
    if due_at and not fill_first(page, PORTAL_MANIFEST["due_date_inputs"], due_at):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")
    if parameters.get("amount_cents") is not None:
        amount = f"{int(parameters['amount_cents']) / 100:.2f}".replace(".", ",")
        if not fill_first(page, PORTAL_MANIFEST["amount_inputs"], amount):
            raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    guide_type = str(parameters.get("guide_type") or "MONTHLY")
    type_patterns = {
        "MONTHLY": [r"mensal"],
        "TERMINATION": [r"rescis"],
        "CONSIGNMENT": [r"consign"],
        "MIXED": [r"mista|mixed"],
        "PARAMETERIZED": [r"parametr"],
    }
    if not click_first_text(page, type_patterns.get(guide_type, [])):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    for selection_key, attribute in (("employee_ids", "employee"), ("debit_ids", "debit")):
        for value in parameters.get(selection_key) or []:
            safe_value = str(value).replace('"', '\\"')
            locator = page.locator(
                f'[data-{attribute}-id="{safe_value}"], input[value="{safe_value}"]'
            ).first
            if locator.count() == 0:
                raise RuntimeError("PORTAL_CONTRACT_CHANGED")
            if not locator.is_checked():
                locator.check()


def authenticated_portal(page: Any, app_url: str) -> bool:
    expected_host = urlparse(app_url).hostname
    current = urlparse(page.url)
    if not expected_host or current.hostname != expected_host:
        return False
    try:
        body = page.locator("body").inner_text(timeout=3_000)
    except Exception:
        return False
    if re.search(r"entrar\s+com\s+gov\.?br", body, re.I):
        return False
    return any(re.search(pattern, body, re.I) for pattern in PORTAL_MANIFEST["authenticated_markers"])


def wait_for_authenticated(page: Any, app_url: str, timeout_ms: int = 30_000) -> bool:
    deadline = time.monotonic() + (timeout_ms / 1_000)
    while time.monotonic() < deadline:
        if authenticated_portal(page, app_url):
            return True
        page.wait_for_timeout(500)
    return False


def authenticate_portal(
    page: Any,
    context: Any,
    login_url: str,
    app_url: str,
    captcha: dict[str, Any],
    provider_proxy: dict[str, str],
) -> None:
    page.goto(login_url, wait_until="domcontentloaded")
    if authenticated_portal(page, app_url):
        return

    if challenge_present(page):
        resolve_challenge(page, context, captcha, provider_proxy)

    click_first_text(page, [r"entrar\s+com\s+gov\.?br"], timeout=12_000)
    try:
        page.wait_for_load_state("domcontentloaded", timeout=15_000)
    except Exception:
        pass
    if challenge_present(page):
        resolve_challenge(page, context, captcha, provider_proxy)

    clicked_certificate = click_first_text(
        page,
        [r"seu\s+certificado\s+digital", r"certificado\s+digital"],
        timeout=12_000,
    )
    if clicked_certificate:
        try:
            page.wait_for_load_state("domcontentloaded", timeout=15_000)
        except Exception:
            pass
    if challenge_present(page):
        resolve_challenge(page, context, captcha, provider_proxy)
        if not authenticated_portal(page, app_url):
            click_first_text(
                page,
                [r"seu\s+certificado\s+digital", r"certificado\s+digital", r"continuar"],
                timeout=8_000,
            )

    if not wait_for_authenticated(page, app_url):
        if challenge_present(page):
            raise RuntimeError("CAPTCHA_TOKEN_REJECTED")
        raise RuntimeError("AUTHENTICATION_NOT_CONFIRMED")


def query_guides(page: Any, app_url: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    page.goto(app_url, wait_until="domcontentloaded")
    if challenge_present(page):
        raise RuntimeError("HUMAN_CHALLENGE_REQUIRED")
    if not click_first_text(page, PORTAL_MANIFEST["guide_navigation"]):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")
    page.wait_for_load_state("domcontentloaded")

    rows = page.locator(PORTAL_MANIFEST["guide_rows"])
    if rows.count() == 0:
        empty = page.get_by_text(re.compile(r"nenhuma guia|n[aã]o h[aá] guias", re.I))
        if empty.count() > 0:
            return [], []
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    guides: list[dict[str, Any]] = []
    artifacts: list[dict[str, Any]] = []
    for index in range(min(rows.count(), 100)):
        cells = [text.strip() for text in rows.nth(index).locator("td").all_inner_texts()]
        if not cells:
            continue
        identifier = safe_row_identifier(cells, "GUIDE-HASH:")
        competence = next((value for value in cells if re.fullmatch(r"\d{2}/\d{4}|\d{4}-\d{2}", value)), None)
        amount_text = next((value for value in cells if "R$" in value), None)
        due = next((value for value in cells if re.fullmatch(r"\d{2}/\d{2}/\d{4}", value)), None)
        guide: dict[str, Any] = {
            "identifier": identifier,
            "competence": normalize_competence(competence),
            "amount_cents": parse_brl_amount(amount_text),
            "due_date": due,
            "payment_status": payment_status_from_cells(cells),
            "guide_type": guide_type_from_cells(cells),
            "checked_at": datetime.now(timezone.utc).isoformat(),
            "manifest_version": PORTAL_MANIFEST_VERSION,
        }

        download_link = rows.nth(index).locator('a:has-text("PDF"), a:has-text("Baixar"), button:has-text("PDF")').first
        if download_link.count() > 0:
            with page.expect_download(timeout=15_000) as pending:
                download_link.click()
            with open(pending.value.path(), "rb") as downloaded:
                content = downloaded.read()
            artifacts.append(artifact(f"fgts-{index + 1}.pdf", "application/pdf", content))
            guide["artifact_index"] = len(artifacts) - 1
        guides.append(guide)
    return guides, artifacts


def query_debts(page: Any, app_url: str) -> list[dict[str, Any]]:
    page.goto(app_url, wait_until="domcontentloaded")
    if challenge_present(page):
        raise RuntimeError("HUMAN_CHALLENGE_REQUIRED")
    if not click_first_text(page, PORTAL_MANIFEST["debt_navigation"]):
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")
    page.wait_for_load_state("domcontentloaded")
    rows = page.locator(PORTAL_MANIFEST["guide_rows"])
    if rows.count() == 0:
        empty = page.get_by_text(re.compile(r"nenhum d[eé]bito|n[aã]o h[aá] d[eé]bitos", re.I))
        if empty.count() > 0:
            return []
        raise RuntimeError("PORTAL_CONTRACT_CHANGED")

    debts: list[dict[str, Any]] = []
    for index in range(min(rows.count(), 500)):
        cells = [text.strip() for text in rows.nth(index).locator("td").all_inner_texts()]
        if cells:
            debts.append(debt_from_cells(cells))
    return debts


def execute_browser(envelope: dict[str, Any]) -> dict[str, Any]:
    try:
        from playwright.sync_api import Error as PlaywrightError
        from playwright.sync_api import sync_playwright
    except ImportError:
        return response("FAILED", "RUNTIME_DEPENDENCY_MISSING", "Playwright não está instalado no runtime.")

    operation = str(envelope["operation"])
    subject = envelope.get("subject") if isinstance(envelope.get("subject"), dict) else {}
    portal = envelope.get("portal") or {}
    login_url = str(portal.get("login_url") or "")
    app_url = str(portal.get("app_url") or "")
    suffixes = [str(value).lower() for value in portal.get("allowed_host_suffixes") or []]
    if not allowed_host(urlparse(login_url).hostname, suffixes) or not allowed_host(urlparse(app_url).hostname, suffixes):
        return response("BLOCKED", "PORTAL_HOST_NOT_ALLOWED", "Host do portal fora da allowlist.")

    credential = envelope.get("credential") or {}
    pfx_base64 = str(credential.get("pfx_base64") or "")
    passphrase = str(credential.get("passphrase") or "")
    pfx = base64.b64decode(pfx_base64, validate=True) if pfx_base64 else None
    state = envelope.get("session") if isinstance(envelope.get("session"), dict) else None
    captcha = envelope.get("captcha") if isinstance(envelope.get("captcha"), dict) else {"driver": "disabled"}
    browser_proxy = None
    provider_proxy: dict[str, str] = {}
    if str(captcha.get("driver") or "disabled") == "nopecha":
        try:
            browser_proxy, provider_proxy = parse_proxy_url(str(captcha.get("proxy_url") or ""))
        except RuntimeError:
            return response(
                "BLOCKED",
                "CAPTCHA_PROXY_INVALID",
                "Proxy opcional do solver de CAPTCHA é inválido.",
            )

    with sync_playwright() as playwright:
        browser = None
        context = None
        mutation_clicked = False
        try:
            browser = playwright.chromium.launch(
                headless=True,
                args=["--disable-dev-shm-usage", "--no-sandbox"],
                proxy=browser_proxy,
            )
            certificates = []
            if pfx:
                for origin in portal.get("certificate_origins") or []:
                    certificates.append({"origin": str(origin), "pfx": pfx, "passphrase": passphrase})
            context = browser.new_context(
                accept_downloads=True,
                locale="pt-BR",
                storage_state=state,
                client_certificates=certificates or None,
                service_workers="block",
            )

            def route_guard(route: Any) -> None:
                host = urlparse(route.request.url).hostname
                if allowed_host(host, suffixes) or route.request.url.startswith(("data:", "blob:")):
                    route.continue_()
                else:
                    route.abort("blockedbyclient")

            context.route("**/*", route_guard)
            page = context.new_page()
            page.set_default_timeout(12_000)
            if operation == "READINESS":
                page.goto(login_url, wait_until="domcontentloaded")
                return response(
                    "SUCCEEDED",
                    "PORTAL_REACHABLE",
                    "Portal FGTS Digital acessível.",
                    {"authenticated": False},
                )

            if state:
                page.goto(app_url, wait_until="domcontentloaded")
            if not state or not authenticated_portal(page, app_url):
                authenticate_portal(page, context, login_url, app_url, captcha, provider_proxy)
            if not authenticated_portal(page, app_url):
                raise RuntimeError("AUTHENTICATION_NOT_CONFIRMED")

            select_profile_and_employer(page, subject)

            session = context.storage_state()
            if operation == "AUTHENTICATE":
                return response(
                    "SUCCEEDED",
                    "SESSION_READY",
                    "Sessão autenticada do portal comprovada.",
                    {"authenticated": True},
                    session=session,
                )

            if operation in {"QUERY_GUIDES", "QUERY_PAYMENT", "DOWNLOAD_GUIDE"}:
                debts = query_debts(page, app_url) if operation == "QUERY_GUIDES" else []
                guides, artifacts = query_guides(page, app_url)
                return response(
                    "SUCCEEDED",
                    "GUIDES_QUERIED" if operation != "QUERY_PAYMENT" else "PAYMENT_QUERIED",
                    "Guias e débitos consultados.",
                    {"guides": guides, "debts": debts},
                    artifacts,
                    session,
                )

            parameters = envelope.get("parameters") or {}
            if operation == "PREVIEW":
                open_emission_form(page, app_url, parameters)
                return response(
                    "SUCCEEDED",
                    "PREVIEW_READY",
                    "Prévia obtida sem emitir a guia.",
                    {"preview": preview_payload(parameters)},
                    session=session,
                )

            if operation == "EMIT_GUIDE":
                existing_guides, existing_artifacts = query_guides(page, app_url)
                equivalent = [guide for guide in existing_guides if guide_equivalent(guide, parameters)]
                if equivalent:
                    return response(
                        "REUSED",
                        "GUIDE_REUSED",
                        "Guia equivalente já existia e foi reutilizada.",
                        {"guides": equivalent},
                        existing_artifacts,
                        session,
                    )

                open_emission_form(page, app_url, parameters)
                # O clique final só ocorre após autorização vinculada validada no Laravel.
                if not click_first_text(page, PORTAL_MANIFEST["emission_confirmation"]):
                    raise RuntimeError("PORTAL_CONTRACT_CHANGED")
                mutation_clicked = True
                page.wait_for_load_state("domcontentloaded")
                guides, artifacts = query_guides(page, app_url)
                reconciled = [guide for guide in guides if guide_equivalent(guide, parameters)]
                if not reconciled:
                    return response(
                        "RECONCILIATION_REQUIRED",
                        "RECONCILIATION_REQUIRED",
                        "A emissão pode ter ocorrido, mas a guia equivalente não foi reconciliada.",
                    )
                return response(
                    "SUCCEEDED",
                    "GUIDE_EMITTED",
                    "Guia emitida e reconciliada.",
                    {"guides": reconciled},
                    artifacts,
                    session,
                )

            return response("BLOCKED", "OPERATION_NOT_ALLOWED", "Operação não autorizada no worker.")
        except RuntimeError as error:
            code = str(error)
            if code == "HUMAN_CHALLENGE_REQUIRED":
                return response("HUMAN_CHALLENGE_REQUIRED", code, "Validação humana necessária.")
            if code in {
                "CAPTCHA_API_KEY_MISSING",
                "CAPTCHA_CONTEXT_MISSING",
                "CAPTCHA_ENDPOINT_NOT_ALLOWED",
                "CAPTCHA_PROVIDER_RESULT_FAILED",
                "CAPTCHA_PROVIDER_SUBMIT_FAILED",
                "CAPTCHA_PROVIDER_TIMEOUT",
                "CAPTCHA_PROXY_INVALID",
                "CAPTCHA_TOKEN_REJECTED",
                "AUTHENTICATION_NOT_CONFIRMED",
                "REPRESENTATION_NOT_CONFIRMED",
            }:
                return response("BLOCKED", code, "Autenticação FGTS Digital não pôde ser comprovada.")
            if code == "PORTAL_CONTRACT_CHANGED" and operation == "EMIT_GUIDE" and mutation_clicked:
                return response(
                    "RECONCILIATION_REQUIRED",
                    "RECONCILIATION_REQUIRED",
                    "O clique de emissão ocorreu, mas a reconciliação não pôde ser concluída.",
                )
            if code == "PORTAL_CONTRACT_CHANGED":
                return response("PORTAL_CONTRACT_CHANGED", code, "O contrato visual do portal mudou; execução interrompida.")
            return response("FAILED", "PORTAL_EXECUTION_FAILED", "Falha sanitizada durante a operação no portal.")
        except (PlaywrightError, ValueError, TimeoutError):
            return response("FAILED", "PORTAL_EXECUTION_FAILED", "Falha sanitizada durante a operação no portal.")
        finally:
            if context is not None:
                context.close()
            if browser is not None:
                browser.close()
            if pfx:
                pfx = b"\0" * len(pfx)
            passphrase = "\0" * len(passphrase)
            for key in ("api_key", "proxy_url"):
                value = str(captcha.get(key) or "")
                captcha[key] = "\0" * len(value)


def main() -> int:
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if not raw or len(raw) > MAX_INPUT_BYTES:
        print(json.dumps(response("BLOCKED", "INPUT_LIMIT", "Envelope ausente ou acima do limite.")))
        return 0
    try:
        envelope = json.loads(raw)
        if int(envelope.get("contract_version", 0)) != CONTRACT_VERSION:
            output = response("BLOCKED", "CONTRACT_VERSION_MISMATCH", "Versão de contrato incompatível.")
        elif str(envelope.get("operation")) not in GUIDE_OPERATIONS:
            output = response("BLOCKED", "OPERATION_NOT_ALLOWED", "Operação não autorizada.")
        else:
            output = execute_browser(envelope)
    except (ValueError, TypeError, json.JSONDecodeError):
        output = response("BLOCKED", "INVALID_ENVELOPE", "Envelope RPA inválido.")
    print(json.dumps(output, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
