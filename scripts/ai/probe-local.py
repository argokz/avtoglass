"""Bounded synthetic checks of the existing local qwen-chat gateway; no cloud calls."""
import argparse
import json
import time
import urllib.error
import urllib.request
from pathlib import Path


def environment(path):
    result = {}
    for line in Path(path).read_text(encoding="utf-8-sig").splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            key, value = line.split("=", 1)
            result[key.strip()] = value.strip().strip('"').strip("'")
    return result


def probe(base, key, scenario):
    payload = {"model": "qwen-chat", "temperature": 0, "max_tokens": 180, "stream": True}
    prompts = {
        "ru": "Ты помощник магазина автостёкол. Клиент: Нужна лобовуха на Камри 2018. Данных каталога нет. Ответь по-русски, максимум 2 предложения, задай нужный вопрос, не называй цену и не обещай совместимость.",
        "kk": "Сен автокөлік әйнегі дүкенінің көмекшісісің. Клиент: Camry 2018 көлігіме алдыңғы әйнек керек. Каталог деректері жоқ. Қазақша екі қысқа сөйлеммен жауап бер, нақтылау сұрағын қой. Баға мен сәйкестікті ойдан шығарма.",
        "tools": "Найди лобовое стекло Toyota Camry 2018 года через search_products. Только вызови инструмент, цену не выдумывай.",
    }
    payload["messages"] = [{"role": "user", "content": prompts[scenario]}]
    if scenario == "tools":
        payload["tools"] = [{"type": "function", "function": {"name": "search_products", "description": "Search glass catalogue", "parameters": {"type": "object", "properties": {"query": {"type": "string"}, "year": {"type": "integer"}}, "required": ["query", "year"]}}}]
        payload["tool_choice"] = "auto"
    request = urllib.request.Request(base.rstrip("/") + "/chat/completions", data=json.dumps(payload).encode(), headers={"Authorization": "Bearer " + key, "Content-Type": "application/json"})
    start = time.monotonic()
    first = None
    text = ""
    calls = {}
    finish = None
    try:
        with urllib.request.urlopen(request, timeout=18) as response:
            for line in response:
                if time.monotonic() - start > 20:
                    raise TimeoutError("turn_budget")
                if not line.startswith(b"data:"):
                    continue
                data = line[5:].strip()
                if data == b"[DONE]":
                    break
                event = json.loads(data)
                for choice in event.get("choices", []):
                    delta = choice.get("delta") or {}
                    chunk = delta.get("content") or ""
                    if chunk and first is None:
                        first = round(time.monotonic() - start, 3)
                    text += chunk
                    for call in delta.get("tool_calls") or []:
                        target = calls.setdefault(call.get("index", 0), {"name": "", "arguments": ""})
                        function = call.get("function") or {}
                        target["name"] += function.get("name") or ""
                        target["arguments"] += function.get("arguments") or ""
                    finish = choice.get("finish_reason") or finish
        return {"scenario": scenario, "status": "received", "first_text_seconds": first, "total_seconds": round(time.monotonic() - start, 3), "text": text, "tool_calls": list(calls.values()), "finish_reason": finish}
    except Exception as error:
        # Exception text can contain endpoint details. Persist only safe type/status.
        return {"scenario": scenario, "status": "failed", "error_type": type(error).__name__, "http_status": getattr(error, "code", None), "total_seconds": round(time.monotonic() - start, 3)}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--scenario", choices=["ru", "kk", "tools", "all"], default="all")
    args = parser.parse_args()
    env = environment(args.env_file)
    base = env.get("LITELLM_BASE_URL", "http://127.0.0.1:4000").rstrip("/")
    if not base.endswith("/v1"):
        base += "/v1"
    key = env.get("LITELLM_API_KEY_1") or env.get("LITELLM_API_KEY")
    if not key:
        raise SystemExit("Local application key is not configured")
    results = []
    for scenario in (["ru", "kk", "tools"] if args.scenario == "all" else [args.scenario]):
        if results:
            time.sleep(20)  # Existing shared gateway documents 4 RPM.
        result = probe(base, key, scenario)
        results.append(result)
        destination = Path(args.output)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
        print(json.dumps({k: v for k, v in result.items() if k not in {"text", "tool_calls"}}, ensure_ascii=False), flush=True)
