import type { CcmeiIssuedCertificateHistoryPayload } from '~/types/fiscal-modules'

/** Histórico local e emissão manual confirmada do certificado CCMEI. */
export function useCcmeiCertificateIssuance() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<CcmeiIssuedCertificateHistoryPayload> {
    return (await fiscal.ccmei.issuedCertificates.history(clientId)).data
  }

  async function requestIssue(clientId: number) {
    return (await fiscal.ccmei.issuedCertificates.issue(clientId)).data
  }

  function downloadPath(clientId: number, certificateId: number): string {
    return fiscal.ccmei.issuedCertificates.downloadPath(clientId, certificateId)
  }

  return { fetchHistory, requestIssue, downloadPath }
}
