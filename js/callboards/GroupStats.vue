<template>
  <div class="group-stats" :id="'block-' + this.groupName">
    <div :class="containerClass">
      <b-card :class="cardClass" :body-class="entry.styleClass" v-for="entry in groupEntries" v-bind:key="entry.id">
        <h1 class="display-5 text-center">{{entry.value}}</h1>
        <p class="text-center mb-1">{{entry.name}}</p>
      </b-card>
    </div>
  </div>
</template>

<script>
import * as settings from '../../settings'
export default {
  name: 'GroupStats',
  props: {
    groupData: {
      type: Object,
      default () {
        return {}
      }
    },
    groupName: {
      type: String,
      default: ''
    },
    containerClass: {
      type: String,
      default: 'd-flex flex-wrap'
    },
    cardClass: {
      type: String,
      default: 'attribute-card p-0 mb-1 mb-sm-1 mb-md-2 mb-xl-4 mx-1 mx-sm-1 mx-md-1 mx-lg-1 mx-xl-2'
    },
    attributes: {
      type: Array,
      default () {
        return []
      }
    },
    limits: {
      type: Object,
      default () {
        return {}
      }
    }
  },
  mounted () {
    this.$store.watch(this.$store.getters.fakeValuesModeEnabled, n => {
      this.groupEntries.forEach((groupEntry, idx) => {
        groupEntry = this.fixItem(groupEntry)
        this.$set(this.groupEntries, idx, this.addStyleClass(groupEntry))
      })
    })
  },
  data () {
    return {
      groupEntries: [],
      version: 0
    }
  },
  watch: {
    groupData (val) {
      let realVal
      if (val.replaced !== undefined || val.updated !== undefined) {
        let newVal = val.replaced || val.updated
        if (newVal.length > 0) {
          newVal.forEach((item, idx) => {
            newVal[idx] = this.addStyleClass(item)
          })
        }

        realVal = newVal
      }

      this.groupEntries.forEach((groupEntry, idx) => {
        if (realVal[groupEntry.id] !== undefined) {
          let newValue = realVal[groupEntry.id]

          // Fix time
          if (typeof newValue === 'string' && newValue.indexOf(':') > -1) {
            if (parseInt(newValue.substring(0, 2)) >= 1) {
              newValue = parseInt(newValue.substring(0, 2)) + ':' + newValue.substring(3)
            } else {
              newValue = newValue.substring(3)
            }
          }

          if (groupEntry.value !== newValue) {
            groupEntry.value = newValue
            groupEntry = this.fixItem(groupEntry)
            this.$set(this.groupEntries, idx, this.addStyleClass(groupEntry))
          }
        }
      })

      this.version = val.version
    }
  },
  created () {
    if (settings[this.groupName] !== undefined) {
      if (this.attributes.length > 0) {
        this.attributes.forEach(attribute => {
          if (settings[this.groupName][attribute] !== undefined) {
            this.groupEntries.push({
              id: attribute,
              name: settings[this.groupName][attribute],
              value: '-',
              styleClass: 'bg-neutral text-white'
            })
          }
        })
      } else {
        for (let idx in settings[this.groupName]) {
          if (settings[this.groupName].hasOwnProperty(idx)) {
            this.groupEntries.push({id: idx, name: settings[this.groupName][idx], value: '-', styleClass: 'bg-neutral text-white'})
          }
        }
      }
    }
  },
  methods: {
    addStyleClass (item) {
      item.styleClass = 'bg-success text-white'

      let splitResult, minutes, seconds

      if (item.id === 'CallsInQueue') {
        if (parseInt(item.value) >= 15) {
          item.styleClass = 'bg-danger text-white'
        } else if (parseInt(item.value) >= 10) {
          item.styleClass = 'bg-warning text-dark'
        } else {
          item.styleClass = 'bg-success text-white'
        }
      }

      if (item.id === 'LongestCallWaiting') {
        splitResult = item.value.split(':')
        if (splitResult.length === 3) {
          item.styleClass = 'bg-danger text-white'
        } else {
          minutes = parseInt(splitResult[0])
          if (minutes >= 2) {
            item.styleClass = 'bg-danger text-white'
          } else if (minutes >= 1) {
            item.styleClass = 'bg-warning text-dark'
          } else {
            item.styleClass = 'bg-success text-white'
          }
        }
      }

      if (item.id === 'AfterCallWork') {
        if (parseInt(item.value) >= 5) {
          item.styleClass = 'bg-danger text-white'
        } else {
          item.styleClass = 'bg-success text-white'
        }
      }

      if (item.id === 'AverageAnswerTime') {
        splitResult = item.value.split(':')
        minutes = parseInt(splitResult[0])
        seconds = parseInt(splitResult[1])
        if (minutes >= 1 || (minutes >= 0 && seconds >= 45)) {
          item.styleClass = 'bg-danger text-white'
        } else if (minutes === 0 && seconds >= 40) {
          item.styleClass = 'bg-warning text-dark'
        } else {
          item.styleClass = 'bg-success text-white'
        }
      }

      if (item.id === 'AverageCallTime') {
        splitResult = item.value.split(':')
        minutes = parseInt(splitResult[0])
        seconds = parseInt(splitResult[1])
        if (minutes >= 5 && seconds >= 0) {
          item.styleClass = 'bg-danger text-white'
        } else if (minutes >= 4 && seconds >= 30) {
          item.styleClass = 'bg-warning text-dark'
        } else {
          item.styleClass = 'bg-success text-white'
        }
      }

      let limitFunction = this.limits[item.id] || this.limits.default
      if (limitFunction !== undefined) {
        let styling = limitFunction(item.value, item.id)
        if (styling === 'danger') {
          item.styleClass = 'bg-danger text-white'
        } else if (styling === 'warning') {
          item.styleClass = 'bg-warning text-dark'
        }
      }

      return item
    },
    fixItem (item) {
      if (this.$store.state.fakeValuesMode && document.querySelector('#block-' + this.groupName).classList.contains('force-green')) {
        if (item.id === 'CallsInQueue') {
          item.value = 0
        } else if (item.id === 'LongestCallWaiting' || item.id === 'AverageCallTime' || item.id === 'AverageAnswerTime' ||
          item.id === 'AverageHandleTime') {
          const time = item.value.split(':')
          item.value = '00:' + time[1]
        }
      }

      return item
    }
  }
}
</script>

<style scoped>

</style>
