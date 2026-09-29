"""Keep opt-in display objects strict and default 1.4 payloads unchanged."""
import copy, json, os, unittest
from pathlib import Path
from jsonschema import Draft202012Validator, FormatChecker
ROOT = Path(__file__).resolve().parents[2]
SPEC = json.loads((ROOT / "docs/openapi.json").read_text())
BASELINE = json.loads((ROOT / "docs/intake-display/baseline-1.4.0.openapi.json").read_text())

class IntakeDisplayContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.samples = [json.loads(line) for line in Path(os.environ["HOLOUL_CONTRACT_SAMPLES"]).read_text().splitlines()]
        cls.details = [s["body"]["data"] for s in cls.samples if s["status"] == 200 and isinstance((s.get("body") or {}).get("data"), dict)
                       and "customer_display_name" in s["body"]["data"] and "latest_revision" in s["body"]["data"]]
        assert cls.details, "Actual opt-in detail responses required"
        cls.actors = [row["actor"] for s in cls.samples if s["status"] == 200 and isinstance((s.get("body") or {}).get("data"), list)
                      for row in s["body"]["data"] if isinstance(row, dict) and "actor" in row]
        assert {a["kind"] for a in cls.actors} == {"staff", "customer", "guest", "system"}, "Actual actor-kind coverage required"

    def valid(self, name, value, spec=SPEC):
        return Draft202012Validator({"components": spec["components"], "$ref": "#/components/schemas/" + name},
                                    format_checker=FormatChecker()).is_valid(value)

    def test_actual_display_details_require_the_opt_in_schema(self):
        extra = {"customer_id", "project_name", "customer_display_name", "provenance", "claimed", "assigned_staff"}
        for detail in self.details:
            self.assertTrue(self.valid("IntakeDisplayDetail", detail))
            self.assertFalse(self.valid("IntakeStaffDetail", detail, BASELINE))
            legacy = {k: v for k, v in detail.items() if k not in extra}
            self.assertTrue(self.valid("IntakeStaffDetail", legacy, BASELINE))
            self.assertFalse(self.valid("IntakeDisplayDetail", legacy))

    def test_each_requested_display_field_is_required(self):
        for field in ("customer_id", "project_name", "customer_display_name", "provenance", "claimed", "assigned_staff"):
            value = copy.deepcopy(self.details[0]); value.pop(field)
            self.assertFalse(self.valid("IntakeDisplayDetail", value), field)

    def test_anonymous_actor_can_never_be_labeled_customer_or_staff(self):
        for actor in self.actors:
            self.assertTrue(self.valid("IntakeDisplayActor", actor))
            forged = {**actor, "kind": "customer", "id": None}
            self.assertFalse(self.valid("IntakeDisplayActor", forged))
            self.assertFalse(self.valid("IntakeDisplayActor", {**forged, "kind": "staff"}))

    def test_actor_kind_is_not_a_role_and_system_has_no_person_name(self):
        actor = self.actors[0]
        self.assertFalse(self.valid("IntakeDisplayActor", {**actor, "kind": "super_admin"}))
        self.assertFalse(self.valid("IntakeDisplayActor", {"id": None, "kind": "system", "display_name": "A staff member"}))

    def test_identity_contact_or_authorization_fields_are_not_allowed(self):
        actor = next(a for a in self.actors if a["kind"] == "staff")
        staff = {k: actor[k] for k in ("id", "display_name")}
        self.assertTrue(self.valid("IntakeDisplayStaff", staff))
        for key, value in (("email", "synthetic@example.test"), ("enabled", False), ("roles", ["super_admin"]), ("phone", "+12025550123")):
            self.assertFalse(self.valid("IntakeDisplayStaff", {**staff, key: value}))
            self.assertFalse(self.valid("IntakeDisplayActor", {**actor, key: value}))

if __name__ == "__main__": unittest.main()
