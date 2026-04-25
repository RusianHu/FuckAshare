import json
import os
import re
import subprocess
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import requests


URL_FILE = "xueqiup.txt"
SIGNER_JS = "tmp_run_waf3.js"
WAF_INLINE_JS = "tmp_waf_inline.js"
OUTPUT_JSON_FILE = "xueqiu_response.json"
MAX_ROUNDS = 4
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/144.0.0.0 Safari/537.36"
)


def first_url(path: str) -> str:
    p = Path(path)
    if not p.exists():
        raise FileNotFoundError(f"missing file: {path}")
    for line in p.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            return line
    raise ValueError(f"no url found in: {path}")


def replace_query_param(url: str, key: str, value: str | None) -> str:
    sp = urlsplit(url)
    pairs = [(k, v) for k, v in parse_qsl(sp.query, keep_blank_values=True) if k != key]
    if value is not None:
        pairs.append((key, value))
    return urlunsplit((sp.scheme, sp.netloc, sp.path, urlencode(pairs, doseq=True), sp.fragment))


def strip_md5(url: str) -> str:
    return replace_query_param(url, "md5__1038", None)


def md5_value(url: str) -> str:
    for k, v in parse_qsl(urlsplit(url).query, keep_blank_values=True):
        if k == "md5__1038":
            return v
    return ""


def extract_signed_url(stdout: str) -> str | None:
    m = re.search(r"^SIGNED_URL=(.+)$", stdout, re.M)
    if m:
        return m.group(1).strip()
    hits = re.findall(r"https?://[^\s\"']*md5__1038=[^\s\"']+", stdout)
    return hits[-1] if hits else None


def extract_cookie_writes(stdout: str) -> list[str]:
    m = re.search(r"^COOKIE_WRITES=(.+)$", stdout, re.M)
    if not m:
        return []
    try:
        data = json.loads(m.group(1))
    except json.JSONDecodeError:
        return []
    return data if isinstance(data, list) else []


def apply_cookie_writes(session: requests.Session, writes: list[str]) -> None:
    latest = {}
    for item in writes:
        first = str(item).split(";", 1)[0].strip()
        if "=" not in first:
            continue
        k, v = first.split("=", 1)
        latest[k] = v
    for k, v in latest.items():
        session.cookies.set(k, v, domain=".xueqiu.com", path="/")


def is_challenge_html(text: str) -> bool:
    return "aliyunwaf_" in text and "renderData" in text


def run_signer(
    session: requests.Session,
    unsigned_url: str,
    challenge_html: str,
) -> tuple[str | None, list[str], str]:
    challenge_path = Path("challenge_runtime.html")
    challenge_path.write_text(challenge_html, encoding="utf-8")

    external_path = None
    m_src = re.search(r"<script[^>]+src=[\"']([^\"']+)[\"']", challenge_html, re.I)
    if m_src:
        src = m_src.group(1)
        if src.startswith("/"):
            src = "https://xueqiu.com" + src
        ext = session.get(src, timeout=20)
        external_path = Path("challenge_external_runtime.js")
        external_path.write_text(ext.text, encoding="utf-8")

    cmd = ["node", SIGNER_JS, unsigned_url, str(challenge_path)]
    if external_path:
        cmd += [WAF_INLINE_JS, str(external_path)]
    proc = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        timeout=25,
        env={**os.environ, "WAF_HARD_EXIT_MS": "12000"},
    )

    signed = extract_signed_url(proc.stdout)
    writes = extract_cookie_writes(proc.stdout)
    err = proc.stderr.strip()[:500] if proc.stderr.strip() else proc.stdout.strip()[:500]
    return signed, writes, err


def probe_json(session: requests.Session, url: str) -> tuple[bool, int, str, str, object | None]:
    r = session.get(url, timeout=20)
    ctype = r.headers.get("Content-Type", "")
    prefix = r.text[:120].replace("\n", " ")
    try:
        data = r.json()
        if isinstance(data, dict):
            if "error_code" in data:
                return False, r.status_code, ctype, f"error_code={data.get('error_code')}", data
            detail = f"dict keys={list(data.keys())[:8]}"
        elif isinstance(data, list):
            detail = f"list size={len(data)}"
        else:
            detail = f"type={type(data).__name__}"
        return True, r.status_code, ctype, detail, data
    except Exception:
        return False, r.status_code, ctype, prefix, None


def save_json_local(data: object, path: str = OUTPUT_JSON_FILE) -> str:
    p = Path(path).resolve()
    p.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    return str(p)


def main() -> int:
    base_url = first_url(URL_FILE)
    unsigned_url = strip_md5(base_url)

    session = requests.Session()
    session.headers.update(
        {
            "User-Agent": UA,
            "Referer": "https://xueqiu.com/hq",
            "Accept": "application/json,text/plain,*/*",
        }
    )

    session.get("https://xueqiu.com/hq", timeout=20)

    # Some endpoints may already be directly accessible without md5__1038.
    ok_unsigned, status_unsigned, ctype_unsigned, detail_unsigned, _ = probe_json(session, unsigned_url)
    if ok_unsigned:
        ok_base, status_base, ctype_base, detail_base, payload_base = probe_json(session, base_url)
        print("[+] unsigned url already returns JSON, signer skipped")
        print("[+] unsigned url:", unsigned_url)
        print("[+] original url from txt:", base_url)
        print(f"[+] verify unsigned json: {ok_unsigned} status={status_unsigned} ctype={ctype_unsigned} detail={detail_unsigned}")
        print(f"[+] verify original txt url json: {ok_base} status={status_base} ctype={ctype_base} detail={detail_base}")
        print("[+] original md5__1038 in txt:", md5_value(base_url))
        if ok_base and payload_base is not None:
            saved = save_json_local(payload_base)
            print("[+] saved json file:", saved)
        return 0 if ok_base else 1

    first = session.get(unsigned_url, timeout=20)
    challenge_html = first.text
    signed_url = None

    for i in range(1, MAX_ROUNDS + 1):
        signed_url, writes, err = run_signer(session, unsigned_url, challenge_html)
        if not signed_url:
            print(f"[-] round {i}: signer failed")
            print(err)
            return 2

        apply_cookie_writes(session, writes)
        ok, status, ctype, detail, _ = probe_json(session, signed_url)
        print(f"[+] round {i}: signed status={status} ctype={ctype} json={ok}")
        if ok:
            new_md5 = md5_value(signed_url)
            txt_url_with_new_md5 = replace_query_param(base_url, "md5__1038", new_md5)
            ok2, status2, ctype2, detail2, payload2 = probe_json(session, txt_url_with_new_md5)
            print("[+] generated md5__1038:", new_md5)
            print("[+] generated signed url:", signed_url)
            print("[+] txt url with new md5:", txt_url_with_new_md5)
            print(f"[+] verify generated signed url json: {ok} {detail}")
            print(f"[+] verify txt url with new md5 json: {ok2} status={status2} ctype={ctype2} detail={detail2}")
            if ok2 and payload2 is not None:
                saved = save_json_local(payload2)
                print("[+] saved json file:", saved)
            return 0 if ok2 else 1

        # Not JSON: if still challenge page, continue solving next round.
        follow = session.get(signed_url, timeout=20)
        if not is_challenge_html(follow.text):
            print(f"[-] round {i}: non-json and not challenge html, prefix={follow.text[:120].replace(chr(10), ' ')}")
            return 1
        challenge_html = follow.text
        unsigned_url = strip_md5(signed_url)

    print("[-] reached max rounds without JSON")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
