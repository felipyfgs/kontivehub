#!/usr/bin/env python3

from pathlib import Path
import sys
import unittest


TOOL_ROOT = Path(__file__).resolve().parent
FIXTURE_ROOT = TOOL_ROOT.parent.parent / "tests" / "Fixtures" / "CodeQuality"
sys.path.insert(0, str(TOOL_ROOT))

from python_inventory import collect_source  # noqa: E402


class PythonInventoryTest(unittest.TestCase):
    def test_collects_class_async_method_nested_function_lambda_and_function(self) -> None:
        source = (FIXTURE_ROOT / "python-valid.py.fixture").read_text(encoding="utf-8")

        result = collect_source(source, "apps/api/rpa/example.py")

        self.assertEqual([], result["parseErrors"])
        kinds = [symbol["kind"] for symbol in result["symbols"]]
        names = [symbol["qualifiedName"] for symbol in result["symbols"]]
        self.assertEqual(["class", "method", "arrow-function", "function", "function"], kinds)
        self.assertIn("Example::execute", names)
        self.assertIn("Example::execute.<locals>.settle", names)
        self.assertIn("standalone", names)
        self.assertTrue(all(len(symbol["fingerprint"]) == 64 for symbol in result["symbols"]))

    def test_invalid_syntax_is_explicit_and_sanitized(self) -> None:
        source = (FIXTURE_ROOT / "python-invalid.py.fixture").read_text(encoding="utf-8")

        result = collect_source(source, "apps/api/rpa/invalid.py")

        self.assertEqual([], result["symbols"])
        self.assertEqual("python", result["parseErrors"][0]["language"])
        self.assertEqual(1, result["parseErrors"][0]["line"])
        self.assertNotIn("\n", result["parseErrors"][0]["message"])


if __name__ == "__main__":
    unittest.main()
