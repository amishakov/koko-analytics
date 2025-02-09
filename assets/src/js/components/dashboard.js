import React, {Component, createElement} from 'react'
import Chart from './chart.js'
import Datepicker from './datepicker.js'
import Totals from './totals.js'
import TopPosts from './top-posts.js'
import TopReferrers from './top-referrers.js'
import Nav from './nav.js'
import datePresets from '../util/date-presets.js'
import { parseISO8601, toISO8601 } from '../util/dates.js'
import { __ } from '@wordpress/i18n'
const settings = window.koko_analytics.settings
let blockComponents = [
  TopPosts, TopReferrers
]
window.koko_analytics.registerDashboardComponent = function(c) {
  blockComponents.push(c)
}

function parseUrlParams (str) {
  const params = {}
  let match
  const matches = str.split('&')

  for (let i = 0; i < matches.length; i++) {
    match = matches[i].split('=')
    params[match[0]] = decodeURIComponent(match[1])
  }

  return params
}

export default class Dashboard extends Component {
  constructor (props) {
    super(props)
    this.state = {
      ...this.parseStateFromLocation(window.location.hash)
    }
    this.setDates = this.setDates.bind(this)
  }

  componentDidMount () {
    this.unlisten = this.props.history.listen(({location, action}) => {
      if (action === 'POP') {
        this.setState(this.parseStateFromLocation(location.search))
      }
    })
  }

  componentWillUnmount () {
    this.unlisten()
  }

  setDatesFromDefaultView () {
    const preset = datePresets.filter(p => p.key === settings.default_view).shift() || datePresets[5]
    return preset.dates ? preset.dates() : {};
  }

  parseStateFromLocation (str) {
    const searchPos = str.indexOf('?')
    if (searchPos === -1) {
      return this.setDatesFromDefaultView()
    }

    const queryStr = str.substring(searchPos + 1)
    const params = parseUrlParams(queryStr)
    if (!params.start_date || !params.end_date) {
      return this.setDatesFromDefaultView()
    }

    const startDate = parseISO8601(params.start_date)
    const endDate = parseISO8601(params.end_date)
    if (!startDate || !endDate) {
      return {}
    }

    startDate.setHours(0, 0, 0)
    endDate.setHours(23, 59, 59)
    return { startDate, endDate }
  }

  setDates (startDate, endDate) {
    if (startDate.getTime() === endDate.getTime()) {
      return
    }

    // update state
    this.setState({ startDate, endDate })

    // update URL
    startDate = toISO8601(startDate)
    endDate = toISO8601(endDate)
    this.props.history.push(`/?start_date=${startDate}&end_date=${endDate}`)
  }

  render () {
    const { startDate, endDate } = this.state
    return (
      <main>
        <div>
          <div className='grid'>
            <div className='four'>
              <Datepicker startDate={startDate} endDate={endDate} onUpdate={this.setDates} />
            </div>
            <Nav history={this.props.history} />
          </div>
          <Totals startDate={startDate} endDate={endDate} />
          <Chart startDate={startDate} endDate={endDate} width={document.getElementById('koko-analytics-mount').clientWidth} />
          <div className='grid'>
            {blockComponents.map((c, key) => createElement(c, {startDate, endDate, key}))}
          </div>
          <div>
            <span className={'description right'}>{__('Tip: use the arrow keys to quickly cycle through date ranges.', 'koko-analytics')}</span>
          </div>
        </div>
      </main>
    )
  }
}
