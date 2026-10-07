import { useNavigate } from 'react-router-dom'
import { setClientView, useClientView } from '@/lib/clientView'
import { toast } from '@/lib/toast'

/**
 * The platform owner's switch between managing an account and seeing it as
 * its client does.
 *
 * Lands on the first screen of the other view: the two have different pages,
 * and an address from one usually means nothing in the other.
 */
export function ViewSwitch() {
  const clientView = useClientView()
  const navigate = useNavigate()

  return (
    <button
      type="button"
      role="switch"
      aria-checked={clientView}
      className="view-switch"
      title={clientView ? 'Back to managing this account' : 'See this account as its client does'}
      onClick={() => {
        setClientView(!clientView)
        void navigate('/')
        toast(clientView ? 'Back to managing' : 'Client view: read-only, as the client sees it')
      }}
    >
      <span className="view-switch__track" aria-hidden="true">
        <span className="view-switch__thumb" />
      </span>
      <span className="view-switch__label">Client view</span>
    </button>
  )
}
