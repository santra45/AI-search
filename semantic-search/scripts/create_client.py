import sys
import os
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from dotenv import load_dotenv
load_dotenv()

from backend.app.services.database import SessionLocal, test_connection
from backend.app.services.license_service import create_client, generate_license_key

def main():
    print("Testing MySQL connection...")
    if not test_connection():
        print("Fix MySQL connection first")
        return

    db = SessionLocal()

    try:
        # Create client
        client = create_client(
            db,
            name="Local Dev Client",
            email="dev@localhost.com",
            plan="growth"
        )
        print(f"\n✅ Client created: {client['id']}")

        # Generate license key
        license_key = generate_license_key(
            db,
            client_id=client['id'],
            allowed_domain="localhost",
            plan="growth",
            valid_days=365
        )

        print(f"\n✅ License key generated:")
        print(f"\n{license_key}\n")
        print(f"Copy this key into your WooCommerce plugin settings")

    except Exception as e:
        print(f"❌ Error: {e}")
    finally:
        db.close()

if __name__ == "__main__":
    main()