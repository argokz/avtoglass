"""Model-only synthetic grounding checks. Does not access the shop DB or create orders."""
import argparse
import json
import time
import urllib.request
from pathlib import Path

from importlib.util import module_from_spec, spec_from_file_location

spec = spec_from_file_location("probe", Path(__file__).with_name("probe-local.py"))
probe = module_from_spec(spec)
spec.loader.exec_module(probe)


def cases():
    rows = []
    vehicles = [("Toyota Camry", 2018), ("Hyundai Tucson", 2020), ("Kia Sportage", 2021), ("Volkswagen Golf", 2015), ("Toyota RAV4", 2019)]
    for index, (vehicle, year) in enumerate(vehicles):
        for locale in ("ru", "kk"):
            price = 27000 + index * 1000
            base = {"vehicle": vehicle, "year": year, "offer_id": 100 + index, "price_kzt": price, "available_qty": 2, "fitment": "matched", "missing_options": []}
            for kind in ("offer", "no_data", "missing_option", "confirmation", "out_of_stock"):
                source = dict(base)
                request = "Подбери стекло" if locale == "ru" else "Әйнек таңдап бер"
                action, expected_price = "offer", price
                if kind == "no_data":
                    source = {"vehicle": vehicle, "year": year, "items": []}
                    action, expected_price = "clarify", None
                elif kind == "missing_option":
                    source.update(fitment="review_required", missing_options=["camera"])
                    action, expected_price = "clarify", None
                elif kind == "confirmation":
                    request = "Оформи заказ" if locale == "ru" else "Тапсырысты рәсімде"
                    action, expected_price = "prepare_confirmation", None
                elif kind == "out_of_stock":
                    source.update(available_qty=0)
                    action, expected_price = "review", None
                rows.append({"id": f"{index + 1}-{locale}-{kind}", "locale": locale, "request": request, "source": source,
                             "expected": {"action": action, "price_kzt": expected_price, "order_created": False}})
    return rows


RULES = """Ты помощник магазина автостёкол. Данные source — единственный источник цен и применимости.
Верни JSON объект: action, price_kzt (число либо null), order_created (всегда false), reply.
Правила выбора action по приоритету: если просят оформить, prepare_confirmation (нужно явное подтверждение в интерфейсе);
если нет предложений, clarify; если available_qty=0, review; если fitment не matched или missing_options непуст, clarify;
иначе offer. price_kzt укажи только при action=offer и строго из source, иначе null.
reply: 1-2 коротких предложения на языке locale. При недостающей camera спроси о камере, а не VIN.
Не обещай совместимость без matched, не говори что заказ создан, не придумывай сроки поставки.
Только JSON, без markdown."""


def run(base, key, case):
    payload = {"model": "qwen-chat", "temperature": 0, "max_tokens": 260, "stream": False,
               "messages": [{"role": "system", "content": RULES}, {"role": "user", "content": json.dumps({k: v for k, v in case.items() if k != "expected"}, ensure_ascii=False)}]}
    request = urllib.request.Request(base + "/chat/completions", data=json.dumps(payload).encode(), headers={"Authorization": "Bearer " + key, "Content-Type": "application/json"})
    started = time.monotonic()
    result = {"id": case["id"]}
    try:
        with urllib.request.urlopen(request, timeout=25) as response:
            data = json.load(response)
        raw = data["choices"][0]["message"]["content"]
        parsed = json.loads(raw)
        errors = [field for field, value in case["expected"].items() if parsed.get(field) != value]
        if not isinstance(parsed.get("reply"), str) or not parsed["reply"].strip():
            errors.append("reply")
        result.update(status="passed" if not errors else "failed", errors=errors, response=parsed,
                      finish_reason=data["choices"][0].get("finish_reason"), usage=data.get("usage"))
    except Exception as error:
        result.update(status="failed", error_type=type(error).__name__, http_status=getattr(error, "code", None))
    result["seconds"] = round(time.monotonic() - started, 3)
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--limit", type=int, default=50)
    args = parser.parse_args()
    env = probe.environment(args.env_file)
    base = env.get("LITELLM_BASE_URL", "http://127.0.0.1:4000").rstrip("/")
    if not base.endswith("/v1"):
        base += "/v1"
    key = env.get("LITELLM_API_KEY_1") or env.get("LITELLM_API_KEY")
    if not key:
        raise SystemExit("Local application key is not configured")
    results = []
    next_start = 0
    for case in cases()[:args.limit]:
        time.sleep(max(0, next_start - time.monotonic()))
        next_start = time.monotonic() + 16
        result = run(base, key, case)
        results.append(result)
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
        print(json.dumps({k: v for k, v in result.items() if k not in {"response", "usage"}}), flush=True)
