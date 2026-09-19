import { createSlice } from '@reduxjs/toolkit';
import store from '../store';

const hotKeySlice = createSlice({
  name: 'hotkey',
  initialState: {
    // Off until Edit Mode enables it: the hotkey dispatcher reads this
    // per keystroke, so outside Edit Mode no binding can fire.
    enabled: false,
  },
  reducers: {
    setHotKey: (state, action) => {
      state.enabled = !!action.payload;
    },
  },
});

export const { setHotKey } = hotKeySlice.actions;
export default hotKeySlice.reducer;

window.setHotKey = (enabled) => {
  store.dispatch(setHotKey(enabled));
};
