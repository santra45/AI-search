from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from sqlalchemy.orm import Session
from sqlalchemy import text
from backend.app.services.database import get_db
from backend.app.services.license_service import validate_license_key
from fastapi import Depends

router = APIRouter()

class WebhookSecretPayload(BaseModel):
    license_key: str
    webhook_secret: str


@router.post("/register-webhook-secret")
def register_webhook_secret(
    payload: WebhookSecretPayload,
    db: Session = Depends(get_db)
):

    # validate license key and get client info using license_service
    try:
        license_info = validate_license_key(payload.license_key, db)
        client_id = license_info["client_id"]
    except ValueError as e:
        raise HTTPException(status_code=404, detail=str(e))

    # save webhook secret
    db.execute(
        text("""
        UPDATE clients
        SET webhook_secret = :secret
        WHERE id = :client_id
        """),
        {
            "secret": payload.webhook_secret,
            "client_id": client_id
        }
    )

    db.commit()

    return {"status": "saved", "client_id": client_id}