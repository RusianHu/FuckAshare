#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import json
from pathlib import Path
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parent
SAMPLES_DIR = ROOT / "samples"
OUTPUT_DIR = ROOT / "outputs"
OUTPUT_DIR.mkdir(exist_ok=True)

FIELD_MAPPING = {
    "quote": {
        "symbol": "证券代码",
        "code": "数字代码",
        "name": "证券名称",
        "exchange": "交易所",
        "current": "最新价",
        "percent": "涨跌幅(%)",
        "chg": "涨跌额",
        "open": "今开",
        "high": "最高",
        "low": "最低",
        "last_close": "昨收",
        "avg_price": "均价",
        "amplitude": "振幅(%)",
        "volume": "成交量",
        "amount": "成交额",
        "turnover_rate": "换手率(%)",
        "volume_ratio": "量比",
        "market_capital": "总市值",
        "float_market_capital": "流通市值",
        "float_shares": "流通股本",
        "total_shares": "总股本",
        "pe_ttm": "市盈率TTM",
        "pb": "市净率",
        "dividend_yield": "股息率(%)",
        "currency": "货币",
        "status": "交易状态",
        "is_registration_desc": "注册制标记",
        "is_vie_desc": "VIE结构说明",
        "timestamp": "行情时间",
        "time": "行情时间文本"
    },
    "kline": {
        "timestamp": "时间",
        "open": "开盘价",
        "high": "最高价",
        "low": "最低价",
        "close": "收盘价",
        "chg": "涨跌额",
        "percent": "涨跌幅(%)",
        "volume": "成交量",
        "amount": "成交额",
        "turnoverrate": "换手率(%)"
    },
    "hot_stock": {
        "symbol": "证券代码",
        "code": "数字代码",
        "name": "证券名称",
        "exchange": "交易所",
        "current": "最新价",
        "percent": "涨跌幅(%)",
        "chg": "涨跌额",
        "value": "热度值",
        "increment": "热度增量",
        "rank_change": "排名变化"
    },
    "screener": {
        "symbol": "证券代码",
        "name": "证券名称",
        "current": "最新价",
        "percent": "涨跌幅(%)",
        "chg": "涨跌额",
        "volume": "成交量",
        "amount": "成交额",
        "turnover_rate": "换手率(%)",
        "volume_ratio": "量比",
        "market_capital": "总市值",
        "float_market_capital": "流通市值",
        "pe_ttm": "市盈率TTM",
        "pb": "市净率",
        "roe_ttm": "净资产收益率TTM(%)",
        "dividend_yield": "股息率(%)",
        "followers": "关注人数",
        "limitup_days": "连板天数"
    },
    "fundx": {
        "id": "内容ID",
        "title": "标题",
        "created_at": "发布时间",
        "description": "摘要",
        "source": "来源",
        "like_count": "点赞数",
        "reply_count": "评论数",
        "retweet_count": "转发数",
        "fav_count": "收藏数",
        "screen_name": "作者昵称",
        "followers_count": "作者粉丝数",
        "target": "详情路径"
    }
}


def load_json(path: Path):
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def ts_to_text(value):
    if not isinstance(value, (int, float)):
        return value
    if value > 10**12:
        dt = datetime.fromtimestamp(value / 1000, tz=timezone.utc).astimezone()
        return dt.strftime("%Y-%m-%d %H:%M:%S %Z")
    return value


def map_fields(raw: dict, mapping: dict):
    out = {}
    for key, cn in mapping.items():
        if key in raw:
            val = raw[key]
            if key in {"timestamp", "created_at"}:
                val = ts_to_text(val)
            out[cn] = val
    return out


def build_entry(*, api_name, sample_file, description, field_mapping, sample_data, source_path):
    return {
        "接口": api_name,
        "样本文件": sample_file,
        "说明": description,
        "中文字段结果": {
            "字段映射": field_mapping,
            "示例数据": sample_data,
        },
        "source_path": str(source_path),
    }


def extract_quote():
    source = SAMPLES_DIR / "quote_detail_sh600519.json"
    data = load_json(source)
    quote = data["data"]["quote"]
    sample_data = map_fields(quote, FIELD_MAPPING["quote"])
    return build_entry(
        api_name="quote_detail",
        sample_file=source.name,
        description="行情详情接口的核心字段中文映射，输出已整理为可直接阅读的‘中文名: 数据’对象。",
        field_mapping=FIELD_MAPPING["quote"],
        sample_data=sample_data,
        source_path=source,
    )


def extract_kline():
    source = SAMPLES_DIR / "kline_sh600519_day.json"
    data = load_json(source)
    columns = data["data"]["column"]
    items = data["data"]["item"][:5]
    mapped_rows = []
    for row in items:
        obj = dict(zip(columns, row))
        mapped_rows.append(map_fields(obj, FIELD_MAPPING["kline"]))
    return build_entry(
        api_name="kline_day",
        sample_file=source.name,
        description="日K线接口前5条样本，已转成中文字段数组。",
        field_mapping=FIELD_MAPPING["kline"],
        sample_data=mapped_rows,
        source_path=source,
    )


def extract_hot_stock():
    source = SAMPLES_DIR / "hot_stock_cn.json"
    data = load_json(source)
    items = data["data"]["items"][:3]
    sample_data = [map_fields(item, FIELD_MAPPING["hot_stock"]) for item in items]
    return build_entry(
        api_name="hot_stock",
        sample_file=source.name,
        description="热门股票列表前3条样本，已保留热度与行情核心字段。",
        field_mapping=FIELD_MAPPING["hot_stock"],
        sample_data=sample_data,
        source_path=source,
    )


def extract_screener():
    source = SAMPLES_DIR / "screener_quote_list.json"
    data = load_json(source)
    items = data["data"]["list"][:3]
    sample_data = [map_fields(item, FIELD_MAPPING["screener"]) for item in items]
    return build_entry(
        api_name="screener_quote_list",
        sample_file=source.name,
        description="条件选股列表前3条样本，按中文字段输出。",
        field_mapping=FIELD_MAPPING["screener"],
        sample_data=sample_data,
        source_path=source,
    )


def extract_fundx():
    source = SAMPLES_DIR / "fundx_public_list.json"
    data = load_json(source)
    items = data["list"][:3]
    out = []
    for item in items:
        flat = {
            "id": item.get("id"),
            "title": item.get("title"),
            "created_at": item.get("created_at"),
            "description": item.get("description"),
            "source": item.get("source"),
            "like_count": item.get("like_count"),
            "reply_count": item.get("reply_count"),
            "retweet_count": item.get("retweet_count"),
            "fav_count": item.get("fav_count"),
            "screen_name": item.get("user", {}).get("screen_name"),
            "followers_count": item.get("user", {}).get("followers_count"),
            "target": item.get("target"),
        }
        out.append(map_fields(flat, FIELD_MAPPING["fundx"]))
    return build_entry(
        api_name="fundx_public_list",
        sample_file=source.name,
        description="动态流接口前3条样本，已展开作者与互动核心字段。",
        field_mapping=FIELD_MAPPING["fundx"],
        sample_data=out,
        source_path=source,
    )


def main():
    outputs = [
        extract_fundx(),
        extract_quote(),
        extract_kline(),
        extract_hot_stock(),
        extract_screener(),
    ]
    result = {
        "生成说明": "基于已验证可用 API 样本生成中文字段结果；本轮明确不包含 search/status 等搜索类接口。",
        "排除接口": ["query/v1/search/status.json", "其他 search/* 接口"],
        "输出特点": [
            "每个接口都提供可直接阅读的‘中文名: 数据’示例结果",
            "保留字段映射，方便从英文原始字段追溯到中文字段",
            "列表型接口输出中文字段数组，单对象接口输出中文字段对象",
        ],
        "接口总数": len(outputs),
        "outputs": outputs,
    }
    out_path = OUTPUT_DIR / "xueqiu_chinese_fields.json"
    with out_path.open("w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
    print(f"written: {out_path}")


if __name__ == "__main__":
    main()
